<?php

use Predis\Client;
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

it('reports distinct users merged across nodes', function () {
    $this->redis->hset('roster-test:presence-room:node-a', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:presence-room:node-a', 'sock-2', 'u-bob');
    // Alice has a second tab connected to a different node.
    $this->redis->hset('roster-test:presence-room:node-b', 'sock-3', 'u-alice');

    $roster = new RoomRoster(config('resonate-roster'));

    expect($roster->users('presence-room'))->toHaveCount(2)
        ->and($roster->userCount('presence-room'))->toBe(2)
        ->and($roster->socketCount('presence-room'))->toBe(3)
        ->and($roster->isOnline('presence-room', 'u-alice'))->toBeTrue()
        ->and($roster->isOnline('presence-room', 'u-nobody'))->toBeFalse();
});

it('returns nothing for an empty channel', function () {
    $roster = new RoomRoster(config('resonate-roster'));

    expect($roster->users('presence-empty'))->toBe([])
        ->and($roster->sockets('presence-empty'))->toBe([])
        ->and($roster->socketCount('presence-empty'))->toBe(0);
});

it('lists every occupied channel', function () {
    $this->redis->hset('roster-test:presence-room:node-a', 'sock-1', 'u-1');
    $this->redis->hset('roster-test:presence-chat.7:node-a', 'sock-2', 'u-2');

    $roster = new RoomRoster(config('resonate-roster'));

    expect($roster->occupiedChannels())
        ->toEqualCanonicalizing(['presence-room', 'presence-chat.7']);
});

it('does not bleed between similarly named channels', function () {
    $this->redis->hset('roster-test:presence-foo:node-a', 'sock-1', 'u-1');
    $this->redis->hset('roster-test:presence-foobar:node-a', 'sock-2', 'u-2');

    $roster = new RoomRoster(config('resonate-roster'));

    expect($roster->socketCount('presence-foo'))->toBe(1)
        ->and($roster->users('presence-foobar'))->toBe(['u-2']);
});
