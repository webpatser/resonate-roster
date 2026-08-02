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
 *
 * The channel is resolved for the connection's own application, so the same
 * channel name on two applications gives two distinct channels.
 */
function subscribePresence(string $channelName, FakeConnection $connection, string $userId): object
{
    $app = $connection->app();
    $data = json_encode(['user_id' => $userId]);

    $channel = app(ChannelManager::class)->for($app)->findOrCreate($channelName);
    $channel->subscribe($connection, presenceAuth($connection->id(), $channelName, $data, $app->secret()), $data);

    return $channel;
}

/**
 * The roster reader, wired to the configured applications.
 */
function pluginRoster(): RoomRoster
{
    return new RoomRoster(config('resonate-roster'), app(ApplicationProvider::class));
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

    $roster = pluginRoster();

    expect($roster->userCount($channelName, 'app-id'))->toBe(2)
        ->and($roster->socketCount($channelName, 'app-id'))->toBe(2)
        ->and($roster->isOnline($channelName, 'u-alice', 'app-id'))->toBeTrue()
        ->and($roster->isOnline($channelName, 'u-bob', 'app-id'))->toBeTrue();
});

it('writes the application into the key', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'presence-room-'.uniqid();

    $alice = new FakeConnection('sock-alice', $app);
    $channel = subscribePresence($channelName, $alice, 'u-alice');

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $channel, $alice) {
        $plugin->boot($context);
        $plugin->onSubscribe($alice, $channel);
    });

    expect($this->redis->keys('roster-test:app-id:'.$channelName.':*'))->toHaveCount(1)
        ->and($this->redis->keys('roster-test:'.$channelName.':*'))->toBe([]);
});

it('ignores non-presence channels in presence track mode', function () {
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

it('mirrors non-presence channels when track is all', function () {
    config()->set('resonate-roster.track', 'all');

    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'updates-'.uniqid();

    $connection = new FakeConnection('sock-1', $app);
    $channel = app(ChannelManager::class)->for($app)->findOrCreate($channelName);
    $channel->subscribe($connection);

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $channel, $connection) {
        $plugin->boot($context);
        $plugin->onSubscribe($connection, $channel);
    });

    $roster = pluginRoster();

    expect($roster->connectionCount($channelName, 'app-id'))->toBe(1)
        ->and($roster->isOccupied($channelName, 'app-id'))->toBeTrue();
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

    $roster = pluginRoster();

    expect($roster->users($channelName, 'app-id'))->toBe(['u-bob'])
        ->and($roster->socketCount($channelName, 'app-id'))->toBe(1);
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

    $roster = pluginRoster();

    expect($roster->users($channelName, 'app-id'))->toBe(['u-alice'])
        ->and($roster->socketCount($channelName, 'app-id'))->toBe(1);
});

it('keeps two applications serving the same channel name apart', function () {
    withSecondApplication();

    $provider = app(ApplicationProvider::class);
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'presence-lobby-'.uniqid();

    $alice = new FakeConnection('sock-alice', $provider->findById('app-id'));
    $bob = new FakeConnection('sock-bob', $provider->findById('app-two'));

    $lobbyOne = subscribePresence($channelName, $alice, 'u-alice');
    $lobbyTwo = subscribePresence($channelName, $bob, 'u-bob');

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $lobbyOne, $lobbyTwo, $alice, $bob) {
        $plugin->boot($context);
        $plugin->onSubscribe($alice, $lobbyOne);
        $plugin->onSubscribe($bob, $lobbyTwo);
    });

    $roster = pluginRoster();

    expect($roster->users($channelName, 'app-id'))->toBe(['u-alice'])
        ->and($roster->users($channelName, 'app-two'))->toBe(['u-bob'])
        ->and($roster->socketCount($channelName, 'app-id'))->toBe(1)
        ->and($roster->socketCount($channelName, 'app-two'))->toBe(1);
});

it('never removes another application members while reconciling', function () {
    withSecondApplication();

    $provider = app(ApplicationProvider::class);
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'presence-lobby-'.uniqid();

    $alice = new FakeConnection('sock-alice', $provider->findById('app-id'));
    $bob = new FakeConnection('sock-bob', $provider->findById('app-two'));

    $lobbyOne = subscribePresence($channelName, $alice, 'u-alice');
    $lobbyTwo = subscribePresence($channelName, $bob, 'u-bob');

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $lobbyOne, $lobbyTwo, $alice, $bob) {
        $plugin->boot($context);
        $plugin->onSubscribe($alice, $lobbyOne);
        $plugin->onSubscribe($bob, $lobbyTwo);

        // The first application's channel empties, so the reconcile pass runs
        // its authoritative rebuild and deletes that key. Before the key
        // carried an application, this pass wiped the second application's
        // members from the very same key.
        $lobbyOne->unsubscribe($alice);

        ($plugin->ticks()[0]['callback'])();
    });

    $roster = pluginRoster();

    expect($roster->users($channelName, 'app-id'))->toBe([])
        ->and($roster->isOccupied($channelName, 'app-id'))->toBeFalse()
        ->and($roster->users($channelName, 'app-two'))->toBe(['u-bob'])
        ->and($roster->socketCount($channelName, 'app-two'))->toBe(1);
});

it('does not touch a pre-0.3.0 key while reconciling', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));
    $channelName = 'presence-room-'.uniqid();

    // A node that has not been upgraded yet owns this key; the upgraded node
    // must leave it alone so the two schemas coexist during a rolling deploy.
    $this->redis->hset('roster-test:'.$channelName.':node-old', 'sock-old', 'u-old');

    $alice = new FakeConnection('sock-alice', $app);
    $channel = subscribePresence($channelName, $alice, 'u-alice');

    $plugin = new RedisRosterPlugin;

    runLoop(function () use ($plugin, $context, $channel, $alice) {
        $plugin->boot($context);
        $plugin->onSubscribe($alice, $channel);

        $channel->unsubscribe($alice);

        ($plugin->ticks()[0]['callback'])();
    });

    expect($this->redis->hgetall('roster-test:'.$channelName.':node-old'))
        ->toBe(['sock-old' => 'u-old']);
});
