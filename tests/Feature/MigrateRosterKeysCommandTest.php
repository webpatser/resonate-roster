<?php

use Predis\Client;

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

it('renames a pre-0.3.0 key into the app-scoped schema', function () {
    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');
    $this->redis->expire('roster-test:presence-room:node-old', 90);

    $this->artisan('resonate-roster:migrate-keys')->assertSuccessful();

    expect($this->redis->hgetall('roster-test:app-id:presence-room:node-old'))
        ->toBe(['sock-1' => 'u-alice'])
        ->and($this->redis->exists('roster-test:presence-room:node-old'))->toBe(0)
        // RENAME carries the TTL over, so the migrated key still self-heals.
        ->and($this->redis->ttl('roster-test:app-id:presence-room:node-old'))->toBeGreaterThan(0);
});

it('reports when there is nothing left to migrate', function () {
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');

    $this->artisan('resonate-roster:migrate-keys')
        ->expectsOutputToContain('No pre-0.3.0 roster keys found')
        ->assertSuccessful();
});

it('changes nothing on a dry run', function () {
    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');

    $this->artisan('resonate-roster:migrate-keys', ['--dry-run' => true])->assertSuccessful();

    expect($this->redis->exists('roster-test:presence-room:node-old'))->toBe(1)
        ->and($this->redis->exists('roster-test:app-id:presence-room:node-old'))->toBe(0);
});

it('leaves a legacy key in place when the target already exists', function () {
    $this->redis->hset('roster-test:presence-room:node-a', 'sock-stale', 'u-stale');
    $this->redis->hset('roster-test:app-id:presence-room:node-a', 'sock-1', 'u-alice');

    $this->artisan('resonate-roster:migrate-keys')->assertSuccessful();

    expect($this->redis->hgetall('roster-test:app-id:presence-room:node-a'))
        ->toBe(['sock-1' => 'u-alice'])
        ->and($this->redis->exists('roster-test:presence-room:node-a'))->toBe(1);
});

it('prunes legacy keys instead of attributing them', function () {
    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');

    $this->artisan('resonate-roster:migrate-keys', ['--prune' => true])->assertSuccessful();

    expect($this->redis->exists('roster-test:presence-room:node-old'))->toBe(0)
        ->and($this->redis->exists('roster-test:app-id:presence-room:node-old'))->toBe(0);
});

it('refuses to attribute a legacy key on a multi-app server', function () {
    withSecondApplication();

    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');

    $this->artisan('resonate-roster:migrate-keys')->assertFailed();

    expect($this->redis->exists('roster-test:presence-room:node-old'))->toBe(1);
});

it('attributes a legacy key to the application it is given', function () {
    withSecondApplication();

    $this->redis->hset('roster-test:presence-room:node-old', 'sock-1', 'u-alice');

    $this->artisan('resonate-roster:migrate-keys', ['--app' => 'app-two'])->assertSuccessful();

    expect($this->redis->hgetall('roster-test:app-two:presence-room:node-old'))
        ->toBe(['sock-1' => 'u-alice']);
});
