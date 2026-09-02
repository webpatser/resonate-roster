<?php

namespace Webpatser\ResonateRoster;

use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\Contracts\TickScheduler;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Mirrors every presence channel into Redis so "who is online" survives a
 * Resonate restart, is correct across nodes, and is queryable from the backend
 * without a metrics round-trip.
 *
 * Membership is kept per application and per node ({@see RosterKeys}): the lifecycle hooks make
 * the fast, incremental edits while the heartbeat tick is the authority that
 * rebuilds each tracked channel from the live connections and refreshes the
 * TTL. A node that dies without firing onClose simply lets its keys expire.
 *
 * The read side is {@see RoomRoster}.
 */
class RedisRosterPlugin implements ConnectionLifecycle, ServerPlugin, TickScheduler
{
    /**
     * The server API surface handed in at boot.
     */
    protected PluginContext $context;

    /**
     * The async Redis client used for all roster writes.
     */
    protected ?RedisClient $redis = null;

    /**
     * The roster key schema.
     */
    protected RosterKeys $keys;

    /**
     * This process's colon-free node identifier.
     */
    protected string $node;

    /**
     * The TTL, in seconds, carried by every roster key.
     */
    protected int $ttl;

    /**
     * The heartbeat interval, in seconds.
     */
    protected float $heartbeat;

    /**
     * Which channels to mirror: 'presence' or 'all'.
     */
    protected string $track;

    /**
     * Channels seen on this node: application id => channel name => true.
     *
     * There is no "all channels" lookup on {@see PluginContext}, so the
     * heartbeat reconciles against this locally tracked set. It is keyed by
     * application first so two applications serving a channel of the same
     * name are two entries, not one that overwrites the other.
     *
     * @var array<string, array<string, true>>
     */
    protected array $tracked = [];

    /**
     * Boot the plugin: read config and open the long-lived Redis client.
     */
    public function boot(PluginContext $context): void
    {
        $this->context = $context;

        $config = config('resonate-roster', []);

        $this->keys = RosterKeys::fromConfig($config);
        $this->ttl = (int) ($config['ttl'] ?? 90);
        $this->heartbeat = (float) ($config['heartbeat_interval'] ?? 30);
        $this->track = $config['track'] ?? 'presence';
        $this->node = RosterKeys::nodeId();
        $this->redis = createRedisClient($this->makeConfig($config['connection'] ?? []));
    }

    /**
     * Handle a connection opening. Nothing to do until it subscribes.
     */
    public function onOpen(Connection $connection): void
    {
        //
    }

    /**
     * Record a presence subscription in this node's roster key.
     */
    public function onSubscribe(Connection $connection, Channel $channel): void
    {
        if ($this->redis === null || ! $this->shouldTrack($channel)) {
            return;
        }

        $name = $channel->name();
        $appId = $connection->app()->id();

        $this->tracked[$appId][$name] = true;

        // Record the channel on the connection itself: onClose fires after the
        // connection has already been stripped from every channel, so this is
        // the only way the close handler can know what to clean up. The
        // application comes off the connection, so it needs no bookkeeping.
        $subscriptions = $connection->state('roster.channels', []);
        $subscriptions[$name] = true;
        $connection->setState('roster.channels', $subscriptions);

        $key = $this->keys->hashKey($appId, $name, $this->node);

        $this->redis->getMap($key)->setValue($connection->id(), $this->userId($connection, $channel));
        $this->redis->expireIn($key, $this->ttl);
    }

    /**
     * Drop a presence subscription left by an explicit pusher:unsubscribe.
     */
    public function onUnsubscribe(Connection $connection, Channel $channel): void
    {
        if ($this->redis === null || ! $this->shouldTrack($channel)) {
            return;
        }

        $name = $channel->name();

        $subscriptions = $connection->state('roster.channels', []);
        unset($subscriptions[$name]);
        $connection->setState('roster.channels', $subscriptions);

        $this->redis->getMap($this->keys->hashKey($connection->app()->id(), $name, $this->node))
            ->remove($connection->id());
    }

    /**
     * Drop every presence subscription held by a closing connection.
     */
    public function onClose(Connection $connection): void
    {
        if ($this->redis === null) {
            return;
        }

        $appId = $connection->app()->id();
        $subscriptions = $connection->state('roster.channels', []);

        // PHP coerces numeric-string array keys to int, so the channel name is
        // restored to a string before it is fed back into the key schema.
        foreach (array_keys($subscriptions) as $name) {
            $this->redis->getMap($this->keys->hashKey($appId, (string) $name, $this->node))
                ->remove($connection->id());
        }

        $connection->forgetState('roster.channels');
    }

    /**
     * Register the heartbeat reconcile tick.
     *
     * @return array<int, array{interval: float, callback: callable():void}>
     */
    public function ticks(): array
    {
        return [
            [
                'interval' => $this->heartbeat,
                'callback' => fn () => $this->reconcile(),
            ],
        ];
    }

    /**
     * Rebuild every tracked channel's key from the live connections.
     *
     * This is the authoritative pass: it adds anything a missed onSubscribe
     * left out, removes anything a missed onClose left behind, refreshes the
     * TTL, and forgets channels that have emptied.
     *
     * Every key it touches is built from the application it is reconciling
     * for, so the pass is confined to one application's own keys: it can never
     * delete another application's members, even when both serve a channel of
     * the same name. It never touches a pre-0.3.0 key either, so during a
     * rolling upgrade an old node keeps owning its own keys.
     */
    protected function reconcile(): void
    {
        if ($this->redis === null) {
            return;
        }

        foreach ($this->tracked as $appId => $channels) {
            // PHP coerces numeric-string array keys to int, and application
            // ids are usually numeric, so both segments are cast back before
            // they are fed into the key schema.
            $appId = (string) $appId;

            foreach (array_keys($channels) as $channel) {
                $this->reconcileChannel($appId, (string) $channel);
            }
        }
    }

    /**
     * Rebuild one application's key for one channel from the live connections.
     */
    protected function reconcileChannel(string $appId, string $name): void
    {
        if ($this->redis === null) {
            return;
        }

        $key = $this->keys->hashKey($appId, $name, $this->node);

        $members = [];

        foreach ($this->context->connectionsOn($appId, $name) as $channelConnection) {
            $members[$channelConnection->connection()->id()] = (string) ($channelConnection->data('user_id') ?? '');
        }

        if ($members === []) {
            $this->redis->delete($key);
            $this->forget($appId, $name);

            return;
        }

        $map = $this->redis->getMap($key);

        $stale = array_values(array_diff($map->getKeys(), array_keys($members)));

        if ($stale !== []) {
            $map->remove(...$stale);
        }

        $map->setValues($members);
        $this->redis->expireIn($key, $this->ttl);
    }

    /**
     * Drop an emptied channel, and its application once it holds none.
     */
    protected function forget(string $appId, string $name): void
    {
        unset($this->tracked[$appId][$name]);

        if (($this->tracked[$appId] ?? []) === []) {
            unset($this->tracked[$appId]);
        }
    }

    /**
     * Determine whether a channel should be mirrored, given the track mode.
     *
     * In 'all' mode every channel is mirrored, so the roster doubles as a
     * cluster-wide occupancy count. In 'presence' mode only presence channels
     * are, and a non-presence channel is left untouched.
     */
    protected function shouldTrack(Channel $channel): bool
    {
        return $this->track === 'all' || $this->isPresenceChannel($channel);
    }

    /**
     * Determine whether a channel is a presence channel.
     */
    protected function isPresenceChannel(Channel $channel): bool
    {
        return str_starts_with($channel->name(), 'presence-');
    }

    /**
     * Resolve the presence user id for a connection on a channel.
     *
     * The id lives in the channel's ChannelConnection data, not on the
     * Connection. A presence channel without a user id stores an empty
     * string, which the reader treats as "no distinct user".
     */
    protected function userId(Connection $connection, Channel $channel): string
    {
        $member = $channel->connections()[$connection->id()] ?? null;

        return (string) ($member?->data('user_id') ?? '');
    }

    /**
     * Build the fledge-fiber Redis configuration from the connection config.
     *
     * `RedisConfig::fromParameters()` reads the Laravel-shaped connection array
     * directly. This used to assemble a `redis://user:pass@host:port/db` string
     * by hand, and everything a URI cannot carry was dropped on the way: the
     * `tls` / `rediss` scheme, unix socket paths, `read_timeout`, the retry
     * settings, the client name and tcp keepalive. A configured `url` still
     * wins, since that form is a URI to begin with.
     *
     * @param  array<string, mixed>  $server
     */
    protected function makeConfig(array $server): RedisConfig
    {
        if (! empty($server['url'])) {
            return RedisConfig::fromUri(
                (string) $server['url'],
                (float) ($server['timeout'] ?? RedisConfig::DEFAULT_TIMEOUT),
            );
        }

        return RedisConfig::fromParameters($server);
    }
}
