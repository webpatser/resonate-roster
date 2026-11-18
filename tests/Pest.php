<?php

use Predis\Client;
use Predis\Connection\Parameters;
use Revolt\EventLoop;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelManager;
use Webpatser\ResonateRoster\RoomRoster;
use Webpatser\ResonateRoster\Tests\Support\CountingConnection;
use Webpatser\ResonateRoster\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Integration');

/*
 * The Pusher channel managers are bound per test so the plugin and the
 * PluginContext exercise a real, isolated channel registry.
 */
uses()->beforeEach(function () {
    $this->app->singleton(ChannelManager::class, fn () => new ArrayChannelManager);
    $this->app->bind(ChannelConnectionManager::class, fn () => new ArrayChannelConnectionManager);
})->in(__DIR__.'/Integration');

/**
 * The port of the test Redis, read from ROSTER_TEST_REDIS_PORT (6379 when unset).
 */
function testRedisPort(): int
{
    $port = getenv('ROSTER_TEST_REDIS_PORT');

    return is_string($port) && $port !== '' ? (int) $port : 6379;
}

/**
 * A reader over a counting predis connection, with the SCAN fallback on or off.
 *
 * @return array{0: RoomRoster, 1: CountingConnection}
 */
function countingRoster(bool $scanFallback): array
{
    $connection = new CountingConnection(Parameters::create([
        'host' => '127.0.0.1',
        'port' => testRedisPort(),
        'database' => 15,
    ]));

    $config = [...config('resonate-roster'), 'scan_fallback' => $scanFallback];

    // A hand-built connection skips predis' connection factory, which is what
    // normally selects the database, so select it here and start counting after.
    $client = new Client($connection);
    $client->select(15);
    $connection->reset();

    return [new RoomRoster($config, app(ApplicationProvider::class), $client), $connection];
}

/**
 * Determine whether a Redis server is reachable for the integration tests.
 */
function redisReachable(): bool
{
    $connection = @fsockopen('127.0.0.1', testRedisPort(), $errno, $errstr, 0.5);

    if ($connection === false) {
        return false;
    }

    fclose($connection);

    return true;
}

/**
 * Configure a second application alongside the first.
 *
 * It mirrors the first application, so both serve the same channel names,
 * which is exactly the collision the app-scoped key schema has to survive.
 */
function withSecondApplication(string $appId = 'app-two', string $key = 'app-two-key', string $secret = 'app-two-secret'): void
{
    $apps = config('reverb.apps.apps');

    $second = $apps[0];
    $second['app_id'] = $appId;
    $second['key'] = $key;
    $second['secret'] = $secret;

    config()->set('reverb.apps.apps', [...$apps, $second]);
}

/**
 * Build a valid Pusher presence auth token for a connection on a channel.
 */
function presenceAuth(string $socketId, string $channel, string $data, string $secret = 'app-secret'): string
{
    return 'app-key:'.hash_hmac('sha256', "{$socketId}:{$channel}:{$data}", $secret);
}

/**
 * Run a closure inside the Revolt event loop, surfacing any failure.
 *
 * The body runs in a loop callback fiber, so the fledge-fiber Redis client's
 * suspending calls work exactly as they do inside the running server.
 */
function runLoop(Closure $body): void
{
    $error = null;

    $watchdog = EventLoop::delay(5.0, function () use (&$error) {
        $error = 'event loop timed out';
        EventLoop::getDriver()->stop();
    });

    EventLoop::queue(function () use ($body, &$error, $watchdog) {
        try {
            $body();
        } catch (Throwable $e) {
            $error = $e->getMessage()."\n".$e->getTraceAsString();
        } finally {
            EventLoop::cancel($watchdog);
            EventLoop::getDriver()->stop();
        }
    });

    EventLoop::run();

    if ($error !== null) {
        throw new RuntimeException($error);
    }
}
