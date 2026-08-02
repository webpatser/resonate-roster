<?php

use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\ResonateRoster\RoomRoster;

beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $this->redis = new Client(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15]);

    flushRosterKeys($this->redis);
});

afterEach(function () {
    if (isset($this->redis)) {
        flushRosterKeys($this->redis);
    }
});

function flushRosterKeys(Client $redis): void
{
    foreach ($redis->keys('roster-test:*') as $key) {
        $redis->del($key);
    }
}

/**
 * A reader wired to the configured applications, as the container builds it.
 */
function roster(): RoomRoster
{
    return new RoomRoster(config('resonate-roster'), app(ApplicationProvider::class));
}

it('reports distinct users merged across nodes', function () {
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-2', 'u-bob');
    // Alice has a second tab connected to a different node.
    $this->redis->hset('roster-test:app-id:presence-room:node-b', 'sock-3', 'u-alice');

    $roster = roster();

    expect($roster->users('presence-room', 'app-id'))->toHaveCount(2)
        ->and($roster->userCount('presence-room', 'app-id'))->toBe(2)
        ->and($roster->socketCount('presence-room', 'app-id'))->toBe(3)
        ->and($roster->isOnline('presence-room', 'u-alice', 'app-id'))->toBeTrue()
        ->and($roster->isOnline('presence-room', 'u-nobody', 'app-id'))->toBeFalse();
});

it('returns nothing for an empty channel', function () {
    $roster = roster();

    expect($roster->users('presence-empty', 'app-id'))->toBe([])
        ->and($roster->sockets('presence-empty', 'app-id'))->toBe([])
        ->and($roster->socketCount('presence-empty', 'app-id'))->toBe(0);
});

it('lists every occupied channel of an application', function () {
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-1');
    $this->redis->hset('roster-test:app-id:presence-chat.7:node-a', 'sock-2', 'u-2');
    $this->redis->hset('roster-test:app-two:presence-elsewhere:node-a', 'sock-3', 'u-3');

    expect(roster()->occupiedChannels('app-id'))
        ->toEqualCanonicalizing(['presence-room', 'presence-chat.7']);
});

it('does not bleed between similarly named channels', function () {
    $this->redis->hset('roster-test:app-id:presence-foo:node-a', 'sock-1', 'u-1');
    $this->redis->hset('roster-test:app-id:presence-foobar:node-a', 'sock-2', 'u-2');

    $roster = roster();

    expect($roster->socketCount('presence-foo', 'app-id'))->toBe(1)
        ->and($roster->users('presence-foobar', 'app-id'))->toBe(['u-2']);
});

it('counts connections on a non-presence channel', function () {
    // A public channel: the roster stores empty user ids, the count still works.
    $this->redis->hset('roster-test:app-id:updates:node-a', 'sock-1', '');
    $this->redis->hset('roster-test:app-id:updates:node-b', 'sock-2', '');

    $roster = roster();

    expect($roster->connectionCount('updates', 'app-id'))->toBe(2)
        ->and($roster->users('updates', 'app-id'))->toBe([])
        ->and($roster->isOccupied('updates', 'app-id'))->toBeTrue()
        ->and($roster->isOccupied('private-quiet', 'app-id'))->toBeFalse();
});

it('keeps two applications that share a channel name apart', function () {
    // The defect this schema fixes: both applications serve "presence-lobby".
    $this->redis->hset('roster-test:app-id:presence-lobby:node-a', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-two:presence-lobby:node-a', 'sock-2', 'u-bob');

    $roster = roster();

    expect($roster->users('presence-lobby', 'app-id'))->toBe(['u-alice'])
        ->and($roster->users('presence-lobby', 'app-two'))->toBe(['u-bob'])
        ->and($roster->socketCount('presence-lobby', 'app-id'))->toBe(1)
        ->and($roster->socketCount('presence-lobby', 'app-two'))->toBe(1)
        ->and($roster->isOnline('presence-lobby', 'u-bob', 'app-id'))->toBeFalse();
});

it('falls back to a pre-0.3.0 key when no app-scoped key exists', function () {
    // Written by a node that has not been upgraded yet.
    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');

    $roster = roster();

    expect($roster->users('presence-room', 'app-id'))->toBe(['u-alice'])
        ->and($roster->socketCount('presence-room', 'app-id'))->toBe(1)
        ->and($roster->isOccupied('presence-room', 'app-id'))->toBeTrue()
        ->and($roster->occupiedChannels('app-id'))->toContain('presence-room');
});

it('prefers the app-scoped key over the pre-0.3.0 key of the same node', function () {
    $this->redis->hset('roster-test:presence-room:node-a', 'sock-stale', 'u-stale');
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');

    $roster = roster();

    expect($roster->users('presence-room', 'app-id'))->toBe(['u-alice'])
        ->and($roster->sockets('presence-room', 'app-id'))->toBe(['sock-1']);
});

it('reads an upgraded and a not-yet-upgraded node together mid-deploy', function () {
    // node-a already runs the new schema, node-b has not been restarted yet.
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:presence-room:node-b', 'sock-2', 'u-bob');

    $roster = roster();

    expect($roster->users('presence-room', 'app-id'))->toEqualCanonicalizing(['u-alice', 'u-bob'])
        ->and($roster->socketCount('presence-room', 'app-id'))->toBe(2);
});

it('ignores pre-0.3.0 keys once the fallback window is closed', function () {
    config()->set('resonate-roster.legacy_fallback', false);

    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-2', 'u-bob');

    $roster = roster();

    expect($roster->users('presence-room', 'app-id'))->toBe(['u-bob'])
        ->and($roster->occupiedChannels('app-id'))->toBe(['presence-room']);
});

it('defaults to the sole configured application', function () {
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');

    expect(roster()->users('presence-room'))->toBe(['u-alice']);
});

it('refuses to guess the application when several are configured', function () {
    withSecondApplication();

    expect(fn () => roster()->users('presence-room'))
        ->toThrow(InvalidArgumentException::class, 'could not resolve a default application');
});
