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
 * Membership is kept per node ({@see RosterKeys}): the lifecycle hooks make
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
     * Presence channels seen on this node: channel name => application id.
     *
     * There is no "all channels" lookup on {@see PluginContext}, so the
     * heartbeat reconciles against this locally tracked set.
     *
     * @var array<string, string>
     */
    protected array $tracked = [];

    /**
     * Boot the plugin: read config and open the long-lived Redis client.
     */
    public function boot(PluginContext $context): void
    {
        $this->context = $context;

        $config = config('resonate-roster', []);

        $this->keys = new RosterKeys($config['key_prefix'] ?? 'roster');
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

        $this->tracked[$name] = $connection->app()->id();

        // Record the channel on the connection itself: onClose fires after the
        // connection has already been stripped from every channel, so this is
        // the only way the close handler can know what to clean up.
        $subscriptions = $connection->state('roster.channels', []);
        $subscriptions[$name] = true;
        $connection->setState('roster.channels', $subscriptions);

        $key = $this->keys->hashKey($name, $this->node);

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

        $this->redis->getMap($this->keys->hashKey($name, $this->node))->remove($connection->id());
    }

    /**
     * Drop every presence subscription held by a closing connection.
     */
    public function onClose(Connection $connection): void
    {
        if ($this->redis === null) {
            return;
        }

        $subscriptions = $connection->state('roster.channels', []);

        foreach (array_keys($subscriptions) as $name) {
            $this->redis->getMap($this->keys->hashKey($name, $this->node))->remove($connection->id());
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
     */
    protected function reconcile(): void
    {
        if ($this->redis === null) {
            return;
        }

        foreach ($this->tracked as $name => $appId) {
            $key = $this->keys->hashKey($name, $this->node);

            $members = [];

            foreach ($this->context->connectionsOn($appId, $name) as $channelConnection) {
                $members[$channelConnection->connection()->id()] = (string) ($channelConnection->data('user_id') ?? '');
            }

            if ($members === []) {
                $this->redis->delete($key);
                unset($this->tracked[$name]);

                continue;
            }

            $map = $this->redis->getMap($key);

            $stale = array_values(array_diff($map->getKeys(), array_keys($members)));

            if ($stale !== []) {
                $map->remove(...$stale);
            }

            $map->setValues($members);
            $this->redis->expireIn($key, $this->ttl);
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
     * Mirrors Resonate's own RedisPubSubProvider so the roster reads its
     * connection exactly the way the rest of the server does.
     *
     * @param  array<string, mixed>  $server
     */
    protected function makeConfig(array $server): RedisConfig
    {
        $timeout = (float) ($server['timeout'] ?? RedisConfig::DEFAULT_TIMEOUT);

        if (! empty($server['url'])) {
            return RedisConfig::fromUri($server['url'], $timeout);
        }

        $host = $server['host'] ?? '127.0.0.1';
        $port = $server['port'] ?? 6379;
        $database = $server['database'] ?? 0;

        $userInfo = '';

        if (! empty($server['password'])) {
            $userInfo = rawurlencode((string) ($server['username'] ?? ''))
                .':'.rawurlencode((string) $server['password']).'@';
        }

        return RedisConfig::fromUri(
            sprintf('redis://%s%s:%s/%s', $userInfo, $host, $port, $database),
            $timeout,
        );
    }
}
