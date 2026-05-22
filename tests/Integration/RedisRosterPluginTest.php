<?php

use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\ResonateRoster\RedisRosterPlugin;
use Webpatser\ResonateRoster\RoomRoster;
use Webpatser\ResonateRoster\Tests\Support\FakeConnection;

beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $this->redis = new Client(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15]);

    foreach ($this->redis->keys('roster-test:*') as $key) {
        $this->redis->del($key);
    }
});

afterEach(function () {
    if (isset($this->redis)) {
        foreach ($this->redis->keys('roster-test:*') as $key) {
            $this->redis->del($key);
        }
    }
});

/**
 * Subscribe a fake connection to a presence channel with a valid auth token.
 */
function subscribePresence(string $channelName, FakeConnection $connection, string $userId): object
{
    $app = app(ApplicationProvider::class)->findById('app-id');
    $data = json_encode(['user_id' => $userId]);

    $channel = app(ChannelManager::class)->for($app)->findOrCreate($channelName);
    $channel->subscribe($connection, presenceAuth($connection->id(), $channelName, $data), $data);

    return $channel;
}

it('mirrors presence subscriptions into redis', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'presence-room-'.uniqid();

    $alice = new FakeConnection('sock-alice', $app);
    $bob = new FakeConnection('sock-bob', $app);

    $channel = subscribePresence($channelName, $alice, 'u-alice');
    subscribePresence($channelName, $bob, 'u-bob');

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $channel, $alice, $bob) {
        $plugin->boot($context);
        $plugin->onSubscribe($alice, $channel);
        $plugin->onSubscribe($bob, $channel);
    });

    $roster = new RoomRoster(config('resonate-roster'));

    expect($roster->userCount($channelName))->toBe(2)
        ->and($roster->socketCount($channelName))->toBe(2)
        ->and($roster->isOnline($channelName, 'u-alice'))->toBeTrue()
        ->and($roster->isOnline($channelName, 'u-bob'))->toBeTrue();
});

it('ignores non-presence channels', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $channel = app(ChannelManager::class)->for($app)->findOrCreate('updates');
    $channel->subscribe($connection);

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $channel, $connection) {
        $plugin->boot($context);
        $plugin->onSubscribe($connection, $channel);
    });

    expect($this->redis->keys('roster-test:*'))->toBe([]);
});

it('removes a connection from the roster when it closes', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'presence-room-'.uniqid();

    $alice = new FakeConnection('sock-alice', $app);
    $bob = new FakeConnection('sock-bob', $app);

    $channel = subscribePresence($channelName, $alice, 'u-alice');
    subscribePresence($channelName, $bob, 'u-bob');

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $channel, $alice, $bob) {
        $plugin->boot($context);
        $plugin->onSubscribe($alice, $channel);
        $plugin->onSubscribe($bob, $channel);
        $plugin->onClose($alice);
    });

    $roster = new RoomRoster(config('resonate-roster'));

    expect($roster->users($channelName))->toBe(['u-bob'])
        ->and($roster->socketCount($channelName))->toBe(1);
});

it('reconciles the roster against the live connections on a heartbeat', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'presence-room-'.uniqid();

    $alice = new FakeConnection('sock-alice', $app);
    $bob = new FakeConnection('sock-bob', $app);

    $channel = subscribePresence($channelName, $alice, 'u-alice');
    subscribePresence($channelName, $bob, 'u-bob');

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $channel, $alice, $bob) {
        $plugin->boot($context);
        $plugin->onSubscribe($alice, $channel);
        $plugin->onSubscribe($bob, $channel);

        // Simulate a missed onClose: bob leaves the channel but the roster
        // still holds his entry. The heartbeat must clean it up.
        $channel->unsubscribe($bob);

        $reconcile = $plugin->ticks()[0]['callback'];
        $reconcile();
    });

    $roster = new RoomRoster(config('resonate-roster'));

    expect($roster->users($channelName))->toBe(['u-alice'])
        ->and($roster->socketCount($channelName))->toBe(1);
});
