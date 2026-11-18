<?php

use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\ResonateRoster\RoomRoster;
use Webpatser\ResonateRoster\RosterKeys;

beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $this->redis = new Client(['host' => '127.0.0.1', 'port' => testRedisPort(), 'database' => 15]);

    flushRosterKeys($this->redis);
});

afterEach(function () {
    if (isset($this->redis)) {
        flushRosterKeys($this->redis);
    }
});

function flushRosterKeys(Client $redis): void
{
    // "roster-test*" also covers the "-nodes:" and "-channels:" index sets.
    foreach ($redis->keys('roster-test*') as $key) {
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

/**
 * Write one node's hash for a channel together with both index sets, the way
 * an upgraded plugin does.
 *
 * @param  array<string, string>  $members  Socket id => presence user id.
 */
function seedIndexed(Client $redis, string $appId, string $channel, string $node, array $members): void
{
    $keys = new RosterKeys('roster-test');

    $redis->hmset($keys->hashKey($appId, $channel, $node), $members);
    $redis->sadd($keys->nodeIndexKey($appId, $channel), [$node]);
    $redis->sadd($keys->channelIndexKey($appId), [$channel]);
}

/**
 * Every public read of one application, for comparing two readers.
 *
 * @param  list<string>  $channels
 * @return array<string, mixed>
 */
function readEverything(RoomRoster $roster, string $appId, array $channels): array
{
    $result = [
        'occupied' => $roster->occupiedChannels($appId),
        'snapshot' => $roster->snapshot($appId),
    ];

    sort($result['occupied']);
    ksort($result['snapshot']);

    foreach ($result['snapshot'] as &$entry) {
        sort($entry['users']);
    }

    unset($entry);

    foreach ($channels as $channel) {
        $users = $roster->users($channel, $appId);
        $sockets = $roster->sockets($channel, $appId);
        sort($users);
        sort($sockets);

        $result[$channel] = [
            'users' => $users,
            'sockets' => $sockets,
            'userCount' => $roster->userCount($channel, $appId),
            'socketCount' => $roster->socketCount($channel, $appId),
            'connectionCount' => $roster->connectionCount($channel, $appId),
            'occupied' => $roster->isOccupied($channel, $appId),
            'aliceOnline' => $roster->isOnline($channel, 'u-alice', $appId),
        ];
    }

    return $result;
}

it('reads indexed data without a single SCAN', function () {
    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-a', ['sock-1' => 'u-alice', 'sock-2' => 'u-bob']);
    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-b', ['sock-3' => 'u-alice']);
    seedIndexed($this->redis, 'app-id', 'updates', 'node-a', ['sock-4' => '']);

    [$roster, $connection] = countingRoster(scanFallback: false);

    $count = $roster->userCount('presence-room', 'app-id');
    $perChannel = $connection->commands;

    $connection->reset();
    $snapshot = $roster->snapshot('app-id');
    $bulk = $connection->commands;

    $connection->reset();
    $occupied = $roster->occupiedChannels('app-id');

    expect($count)->toBe(2)
        ->and($perChannel)->toBe(['SMEMBERS', 'HGETALL', 'HGETALL'])
        ->and($snapshot['presence-room']['connections'])->toBe(3)
        ->and($snapshot['updates']['connections'])->toBe(1)
        ->and($bulk)->toBe(['SMEMBERS', 'SMEMBERS', 'SMEMBERS', 'HGETALL', 'HGETALL', 'HGETALL'])
        ->and($occupied)->toEqualCanonicalizing(['presence-room', 'updates'])
        ->and($connection->count('SCAN'))->toBe(0);
});

it('finds data written without the index only while the scan fallback is on', function () {
    // A node on the previous release writes the hash and no index at all.
    $this->redis->hset('roster-test:app-id:presence-room:node-old', 'sock-1', 'u-alice');
    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-new', ['sock-2' => 'u-bob']);

    [$fallback, $scanning] = countingRoster(scanFallback: true);
    [$indexed] = countingRoster(scanFallback: false);

    expect($fallback->users('presence-room', 'app-id'))->toEqualCanonicalizing(['u-alice', 'u-bob'])
        ->and($scanning->count('SCAN'))->toBeGreaterThan(0)
        ->and($fallback->snapshot('app-id')['presence-room']['connections'])->toBe(2)
        ->and($indexed->users('presence-room', 'app-id'))->toBe(['u-bob'])
        ->and($indexed->snapshot('app-id')['presence-room']['connections'])->toBe(1);
});

it('scans by default so an upgrade loses nothing', function () {
    $this->redis->hset('roster-test:app-id:presence-room:node-old', 'sock-1', 'u-alice');

    expect(roster()->users('presence-room', 'app-id'))->toBe(['u-alice']);
});

it('drops a dead node from the index once its hash expires', function () {
    $keys = new RosterKeys('roster-test');

    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-live', ['sock-1' => 'u-alice']);
    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-dead', ['sock-2' => 'u-bob']);
    seedIndexed($this->redis, 'app-id', 'presence-gone', 'node-dead', ['sock-3' => 'u-carol']);

    // The dead node stops refreshing: its hashes expire, its index entries
    // stay. Deleting the hashes is what their expiry does, without the wait.
    $this->redis->del($keys->hashKey('app-id', 'presence-room', 'node-dead'));
    $this->redis->del($keys->hashKey('app-id', 'presence-gone', 'node-dead'));

    [$roster] = countingRoster(scanFallback: false);

    expect($roster->users('presence-room', 'app-id'))->toBe(['u-alice'])
        ->and($roster->isOnline('presence-room', 'u-bob', 'app-id'))->toBeFalse()
        ->and($roster->isOccupied('presence-gone', 'app-id'))->toBeFalse()
        ->and($roster->occupiedChannels('app-id'))->toBe(['presence-room'])
        ->and(array_keys($roster->snapshot('app-id')))->toBe(['presence-room'])
        ->and($this->redis->smembers($keys->nodeIndexKey('app-id', 'presence-room')))->toBe(['node-live'])
        ->and($this->redis->exists($keys->nodeIndexKey('app-id', 'presence-gone')))->toBe(0)
        ->and($this->redis->smembers($keys->channelIndexKey('app-id')))->toBe(['presence-room']);
});

it('keeps the read connection and stops trimming when the server refuses scripts', function () {
    $keys = new RosterKeys('roster-test');

    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-live', ['sock-1' => 'u-alice']);
    $this->redis->sadd($keys->nodeIndexKey('app-id', 'presence-room'), ['node-dead']);

    [$roster, $connection] = countingRoster(scanFallback: false);
    $connection->rejectScripts = true;

    $first = $roster->users('presence-room', 'app-id');
    $firstScripts = $connection->count('EVAL');

    $second = $roster->users('presence-room', 'app-id');
    $secondScripts = $connection->count('EVAL') - $firstScripts;

    expect($first)->toBe(['u-alice'])
        ->and($second)->toBe(['u-alice'])
        ->and($firstScripts)->toBe(1)
        ->and($secondScripts)->toBe(0)
        ->and($connection->disconnects)->toBe(0)
        ->and($connection->connects)->toBe(1);
});

it('returns the same results from the index as from a scan for a mixed fixture', function () {
    withSecondApplication();

    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-a', ['sock-1' => 'u-alice', 'sock-2' => 'u-bob']);
    seedIndexed($this->redis, 'app-id', 'presence-room', 'node-b', ['sock-3' => 'u-alice']);
    seedIndexed($this->redis, 'app-id', 'presence-room.7', 'node-a', ['sock-4' => 'u-carol']);
    seedIndexed($this->redis, 'app-id', 'presence-room.7:x', 'node-b', ['sock-5' => 'u-mallory']);
    seedIndexed($this->redis, 'app-id', 'updates', 'node-a', ['sock-6' => '', 'sock-7' => '']);
    seedIndexed($this->redis, 'app-id', '42', 'node-a', ['sock-8' => '7']);
    seedIndexed($this->redis, 'app-two', 'presence-room', 'node-a', ['sock-9' => 'u-dave']);

    // A dead node: still in the indexes, its hashes long expired.
    $keys = new RosterKeys('roster-test');
    $this->redis->sadd($keys->nodeIndexKey('app-id', 'presence-room'), ['node-ghost']);
    $this->redis->sadd($keys->nodeIndexKey('app-id', 'presence-ghost'), ['node-ghost']);
    $this->redis->sadd($keys->channelIndexKey('app-id'), ['presence-ghost']);

    $channels = ['presence-room', 'presence-room.7', 'presence-room.7:x', 'updates', '42', 'presence-empty', 'presence-ghost'];

    [$scan] = countingRoster(scanFallback: true);
    [$index] = countingRoster(scanFallback: false);

    expect(readEverything($index, 'app-id', $channels))->toBe(readEverything($scan, 'app-id', $channels))
        ->and(readEverything($index, 'app-two', $channels))->toBe(readEverything($scan, 'app-two', $channels));
});

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

it('ignores pre-0.3.0 unscoped keys', function () {
    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-2', 'u-bob');

    $roster = roster();

    expect($roster->users('presence-room', 'app-id'))->toBe(['u-bob'])
        ->and($roster->occupiedChannels('app-id'))->toBe(['presence-room']);
});

it('snapshots every occupied channel with its users and connections', function () {
    // presence-room: three sockets over two nodes, two distinct users.
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-2', 'u-bob');
    $this->redis->hset('roster-test:app-id:presence-room:node-b', 'sock-3', 'u-alice');

    // presence-chat.7: one socket, one user.
    $this->redis->hset('roster-test:app-id:presence-chat.7:node-a', 'sock-4', 'u-carol');

    // A public channel: connections without presence users.
    $this->redis->hset('roster-test:app-id:updates:node-a', 'sock-5', '');
    $this->redis->hset('roster-test:app-id:updates:node-b', 'sock-6', '');

    // Another application's room, which this snapshot must not see.
    $this->redis->hset('roster-test:app-two:presence-elsewhere:node-a', 'sock-7', 'u-dave');

    $snapshot = roster()->snapshot('app-id');

    expect($snapshot)->toHaveCount(3)
        ->and($snapshot['presence-room']['connections'])->toBe(3)
        ->and($snapshot['presence-room']['users'])->toEqualCanonicalizing(['u-alice', 'u-bob'])
        ->and($snapshot['presence-chat.7']['connections'])->toBe(1)
        ->and($snapshot['presence-chat.7']['users'])->toBe(['u-carol'])
        ->and($snapshot['updates']['connections'])->toBe(2)
        ->and($snapshot['updates']['users'])->toBe([])
        ->and($snapshot)->not->toHaveKey('presence-elsewhere');
});

it('returns an empty snapshot when nothing is occupied', function () {
    expect(roster()->snapshot('app-id'))->toBe([]);
});

it('keeps two applications that share a channel name apart in a snapshot', function () {
    $this->redis->hset('roster-test:app-id:presence-lobby:node-a', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-id:presence-lobby:node-a', 'sock-2', 'u-bob');
    $this->redis->hset('roster-test:app-two:presence-lobby:node-a', 'sock-3', 'u-carol');

    $roster = roster();

    $first = $roster->snapshot('app-id');
    $second = $roster->snapshot('app-two');

    expect($first['presence-lobby']['connections'])->toBe(2)
        ->and($first['presence-lobby']['users'])->toEqualCanonicalizing(['u-alice', 'u-bob'])
        ->and($second['presence-lobby']['connections'])->toBe(1)
        ->and($second['presence-lobby']['users'])->toBe(['u-carol']);
});

it('ignores pre-0.3.0 unscoped keys in a snapshot', function () {
    $this->redis->hset('roster-test:presence-old:node-old', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-2', 'u-bob');

    $snapshot = roster()->snapshot('app-id');

    expect(array_keys($snapshot))->toBe(['presence-room'])
        ->and($snapshot['presence-room']['users'])->toBe(['u-bob']);
});

it('snapshots the sole configured application by default', function () {
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');

    expect(roster()->snapshot()['presence-room']['users'])->toBe(['u-alice']);
});

it('refuses to guess the application for a snapshot', function () {
    withSecondApplication();

    expect(fn () => roster()->snapshot())
        ->toThrow(InvalidArgumentException::class, 'could not resolve a default application');
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

it('does not read a channel whose name extends another with a colon', function () {
    $this->redis->hset('roster-test:app-id:presence-room.7:node-a', 'sock-1', 'u-alice');
    $this->redis->hset('roster-test:app-id:presence-room.7:x:node-b', 'sock-2', 'u-mallory');

    $roster = roster();

    expect($roster->users('presence-room.7', 'app-id'))->toBe(['u-alice'])
        ->and($roster->userCount('presence-room.7', 'app-id'))->toBe(1)
        ->and($roster->isOnline('presence-room.7', 'u-mallory', 'app-id'))->toBeFalse();
});
