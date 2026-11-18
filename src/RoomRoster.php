<?php

namespace Webpatser\ResonateRoster;

use InvalidArgumentException;
use Predis\Client;
use Predis\ClientContextInterface;
use Predis\Command\RawCommand;
use Predis\Response\ErrorInterface;
use Predis\Response\ServerException;
use Webpatser\Resonate\Contracts\ApplicationProvider;

/**
 * The read side of the roster.
 *
 * A plain synchronous query API for the host app, a billing meter, or any
 * other backend code: it answers "who is online in channel C of application A"
 * by reading the per-node Redis hashes the {@see RedisRosterPlugin} writes,
 * with no metrics round-trip to the socket server.
 *
 * The hashes are found through the index sets the plugin keeps
 * ({@see RosterKeys::channelIndexKey()}, {@see RosterKeys::nodeIndexKey()}),
 * or, while the "scan_fallback" config flag is on, with a keyspace SCAN that
 * also finds hashes written by nodes on a release without the index.
 *
 * Every method takes an optional application id as its last argument. Leave it
 * out on a single-app server and the sole configured application is used; a
 * server with several applications must pass it, since a channel name alone
 * does not identify a roster any more.
 *
 * It uses predis (pure PHP, no extension required), since the consuming app
 * is an ordinary Laravel request, not the fiber runtime.
 */
class RoomRoster
{
    /**
     * Drop a node from a channel's node index while its hash is missing, and
     * the channel from the application's channel index once no node is left.
     * KEYS: hash, node index, channel index. ARGV: node id, channel.
     */
    protected const TRIM_NODE_SCRIPT = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then
            redis.call('SREM', KEYS[2], ARGV[1])
        end
        if redis.call('SCARD', KEYS[2]) == 0 then
            redis.call('SREM', KEYS[3], ARGV[2])
        end
        return 1
        LUA;

    /**
     * Drop a channel from the application's channel index while its node
     * index is empty. KEYS: node index, channel index. ARGV: channel.
     */
    protected const TRIM_CHANNEL_SCRIPT = <<<'LUA'
        if redis.call('SCARD', KEYS[1]) == 0 then
            redis.call('SREM', KEYS[2], ARGV[1])
        end
        return 1
        LUA;

    /**
     * The roster key schema.
     */
    protected RosterKeys $keys;

    /**
     * The sole configured application id, resolved on first use.
     */
    protected ?string $defaultAppId = null;

    /**
     * Whether the server refused a trim script, so none is sent again.
     */
    protected bool $trimRefused = false;

    /**
     * Create a new roster reader.
     *
     * @param  array<string, mixed>  $config  The "resonate-roster" config array.
     * @param  ApplicationProvider|null  $applications  Used to resolve the default application id.
     * @param  Client|null  $client  A ready predis client; built from the config on first use when left null.
     */
    public function __construct(
        protected array $config,
        protected ?ApplicationProvider $applications = null,
        protected ?Client $client = null,
    ) {
        $this->keys = RosterKeys::fromConfig($config);
    }

    /**
     * The distinct presence user ids online in a channel.
     *
     * @return list<string>
     */
    public function users(string $channel, ?string $appId = null): array
    {
        $users = [];

        foreach ($this->channelHashes($channel, $appId) as $hash) {
            foreach ($hash as $userId) {
                if ($userId !== '') {
                    $users[$userId] = true;
                }
            }
        }

        // PHP coerces numeric-string array keys ("42") to ints, so the ids are
        // cast back to make this the list<string> the signature promises.
        return array_map('strval', array_keys($users));
    }

    /**
     * The socket ids online in a channel.
     *
     * @return list<string>
     */
    public function sockets(string $channel, ?string $appId = null): array
    {
        $sockets = [];

        foreach ($this->channelHashes($channel, $appId) as $hash) {
            foreach (array_keys($hash) as $socketId) {
                $sockets[$socketId] = true;
            }
        }

        return array_map('strval', array_keys($sockets));
    }

    /**
     * The number of distinct users online in a channel.
     */
    public function userCount(string $channel, ?string $appId = null): int
    {
        return count($this->users($channel, $appId));
    }

    /**
     * The number of sockets online in a channel.
     */
    public function socketCount(string $channel, ?string $appId = null): int
    {
        return count($this->sockets($channel, $appId));
    }

    /**
     * The number of connections in a channel.
     *
     * An alias of {@see socketCount()} that reads more naturally for
     * non-presence channels, where "sockets" and "connections" are the same
     * thing and there is no presence user to speak of.
     */
    public function connectionCount(string $channel, ?string $appId = null): int
    {
        return $this->socketCount($channel, $appId);
    }

    /**
     * Determine whether a channel has at least one connection.
     */
    public function isOccupied(string $channel, ?string $appId = null): bool
    {
        if ($this->scanFallback()) {
            return $this->nodeKeys($channel, $appId) !== [];
        }

        return $this->channelHashes($channel, $appId) !== [];
    }

    /**
     * Determine whether a user is online in a channel.
     */
    public function isOnline(string $channel, string $userId, ?string $appId = null): bool
    {
        return in_array($userId, $this->users($channel, $appId), true);
    }

    /**
     * Every channel of an application that currently has at least one member.
     *
     * @return list<string>
     */
    public function occupiedChannels(?string $appId = null): array
    {
        $app = $this->appId($appId);

        if (! $this->scanFallback()) {
            return array_map('strval', array_keys($this->indexedHashes($app)));
        }

        $channels = [];

        foreach ($this->keysMatching($this->keys->appPattern($app)) as $key) {
            $channel = $this->keys->channelFromKey($app, $key);

            if ($channel !== null) {
                $channels[$channel] = true;
            }
        }

        return array_map('strval', array_keys($channels));
    }

    /**
     * Every occupied channel of an application, with its users and connections.
     *
     * The bulk read, for a dashboard or a billing sweep that wants the whole
     * application at once rather than one channel at a time. Asking the
     * per-channel methods for C channels costs 1 + 2C full keyspace sweeps,
     * which at a few hundred channels is a few hundred sweeps per poll against
     * the same Redis the socket server is using. With the SCAN fallback on this
     * is one sweep plus a single pipelined batch of HGETALLs; with it off it is
     * three round trips through the index sets and no SCAN at all. Either way
     * the hash values are the presence user ids and the field count is the
     * connection count.
     *
     * With the SCAN fallback on, a channel whose node key was found but came
     * back empty is reported with no users and no connections. Through the
     * index such a channel is left out. Redis drops a hash when its last field
     * goes, so an empty one is a momentary state and the two agree in practice.
     *
     * @return array<string, array{users: list<string>, connections: int}> Keyed by channel name.
     */
    public function snapshot(?string $appId = null): array
    {
        $app = $this->appId($appId);

        $read = $this->scanFallback() ? $this->scannedHashes($app) : $this->indexedHashes($app);

        /** @var array<string, int> $connections */
        $connections = [];

        /** @var array<string, array<string, true>> $users */
        $users = [];

        foreach ($read as $channel => $hashes) {
            $channel = (string) $channel;

            $connections[$channel] = 0;

            foreach ($hashes as $hash) {
                $connections[$channel] += count($hash);

                // The hash is socket id => presence user id. A blank user id is
                // a non-presence member, which counts as a connection but not
                // as a distinct user, the same way users() treats it.
                foreach ($hash as $userId) {
                    if (is_string($userId) && $userId !== '') {
                        $users[$channel][$userId] = true;
                    }
                }
            }
        }

        $channels = [];

        foreach ($connections as $channel => $count) {
            $channel = (string) $channel;

            $channels[$channel] = [
                'users' => array_map('strval', array_keys($users[$channel] ?? [])),
                'connections' => $count,
            ];
        }

        return $channels;
    }

    /**
     * The keys to read for every channel of an application, node by node.
     *
     * What {@see nodeKeys()} does for one channel, applied to a whole
     * application in one pass, so it costs a single sweep at any channel
     * count.
     *
     * @return array<string, array<string, string>> Channel name => node id => key.
     */
    protected function snapshotKeys(string $appId): array
    {
        $keys = [];

        foreach ($this->keysMatching($this->keys->appPattern($appId)) as $key) {
            $channel = $this->keys->channelFromKey($appId, $key);

            if ($channel !== null) {
                $keys[$channel][$this->keys->nodeFromKey($key)] = $key;
            }
        }

        return $keys;
    }

    /**
     * Every channel of an application found by SCAN, with its node hashes.
     *
     * A channel whose key was found keeps its entry even when its hashes come
     * back empty, which is how {@see snapshot()} read before the index.
     *
     * @return array<string, list<array<array-key, mixed>>> Channel name => node hashes.
     */
    protected function scannedHashes(string $appId): array
    {
        $read = [];
        $flat = [];
        $owners = [];

        foreach ($this->snapshotKeys($appId) as $channel => $nodeKeys) {
            $channel = (string) $channel;
            $read[$channel] = [];

            foreach ($nodeKeys as $key) {
                $flat[] = $key;
                $owners[] = $channel;
            }
        }

        foreach ($this->hashes($flat) as $index => $hash) {
            $channel = $owners[$index] ?? null;

            if ($channel !== null) {
                $read[$channel][] = $hash;
            }
        }

        return $read;
    }

    /**
     * Every channel of an application found through the index sets, with its
     * non-empty node hashes.
     *
     * Three round trips at any channel and node count, and no SCAN: SMEMBERS of
     * the channel index, a pipelined SMEMBERS of every channel's node index,
     * and a pipelined HGETALL of every indexed hash. A channel is reported only
     * when at least one of its hashes still exists, so an index entry left by a
     * node that died without cleaning up is never reported; such entries are
     * trimmed on the way out.
     *
     * @return array<string, list<array<array-key, mixed>>> Channel name => node hashes.
     */
    protected function indexedHashes(string $appId): array
    {
        $channels = array_map('strval', $this->members($this->keys->channelIndexKey($appId)));

        if ($channels === []) {
            return [];
        }

        $nodeSets = $this->pipelinedMembers(array_map(
            fn (string $channel): string => $this->keys->nodeIndexKey($appId, $channel),
            $channels,
        ));

        $flat = [];
        $owners = [];
        $emptyChannels = [];

        foreach ($channels as $index => $channel) {
            $nodes = $nodeSets[$index] ?? [];

            if ($nodes === []) {
                $emptyChannels[] = $channel;

                continue;
            }

            foreach ($nodes as $node) {
                $flat[] = $this->keys->hashKey($appId, $channel, $node);
                $owners[] = [$channel, $node];
            }
        }

        $read = [];
        $stale = [];

        foreach ($this->hashes($flat) as $index => $hash) {
            $owner = $owners[$index] ?? null;

            if ($owner === null) {
                continue;
            }

            if ($hash === []) {
                $stale[] = $owner;

                continue;
            }

            $read[$owner[0]][] = $hash;
        }

        $this->trim($appId, $stale, $emptyChannels);

        return $read;
    }

    /**
     * The non-empty node hashes of one channel of an application.
     *
     * With the SCAN fallback on this is the sweep plus one pipelined HGETALL it
     * always was. With it off it is one SMEMBERS of the channel's node index and
     * one pipelined HGETALL, with no SCAN; an indexed node whose hash has
     * expired is skipped and trimmed from the index.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function channelHashes(string $channel, ?string $appId): array
    {
        $appId = $this->appId($appId);

        if ($this->scanFallback()) {
            return $this->hashes($this->nodeKeys($channel, $appId));
        }

        $nodes = $this->members($this->keys->nodeIndexKey($appId, $channel));

        $keys = array_map(fn (string $node): string => $this->keys->hashKey($appId, $channel, $node), $nodes);

        $live = [];
        $stale = [];

        foreach ($this->hashes($keys) as $index => $hash) {
            if ($hash === []) {
                $stale[] = [$channel, $nodes[$index] ?? ''];

                continue;
            }

            $live[] = $hash;
        }

        $this->trim($appId, $stale, []);

        return $live;
    }

    /**
     * Remove index entries a read found pointing at nothing.
     *
     * Each removal is a script that re-checks the state inside Redis before it
     * deletes anything: a node is dropped from a node index only while its hash
     * is still missing, and a channel from the channel index only while its
     * node index is empty. A node that writes its hash between this read and
     * the trim therefore keeps its entry, and a node that loses one by a race
     * re-adds it on its next write or heartbeat.
     *
     * Trimming is best effort and never disturbs the read connection. Each
     * script is its own command, outside any pipeline: predis drops the
     * connection when a pipelined reply is an error, but a plain command's
     * error reply leaves it open. The first refusal of any kind (a read-only
     * replica, an ACL user without EVAL, scripting disabled) stops trimming
     * for the rest of this instance's life, so a reader that may not write
     * pays for it once rather than on every read. The results stay correct
     * either way: the read already left every entry without a live hash out.
     *
     * @param  list<array{0: string, 1: string}>  $staleNodes  Channel and node pairs whose hash is gone.
     * @param  list<string>  $emptyChannels  Channels whose node index is empty.
     */
    protected function trim(string $appId, array $staleNodes, array $emptyChannels): void
    {
        if ($this->trimRefused || ($staleNodes === [] && $emptyChannels === [])) {
            return;
        }

        $channelIndex = $this->keys->channelIndexKey($appId);

        // EVAL takes every key through KEYS; predis' eval() signature only
        // declares two of the variadic arguments, so the command is built raw.
        $commands = [];

        foreach ($staleNodes as [$channel, $node]) {
            $commands[] = new RawCommand('EVAL', [
                self::TRIM_NODE_SCRIPT,
                3,
                $this->keys->hashKey($appId, $channel, $node),
                $this->keys->nodeIndexKey($appId, $channel),
                $channelIndex,
                $node,
                $channel,
            ]);
        }

        foreach ($emptyChannels as $channel) {
            $commands[] = new RawCommand('EVAL', [
                self::TRIM_CHANNEL_SCRIPT,
                2,
                $this->keys->nodeIndexKey($appId, $channel),
                $channelIndex,
                $channel,
            ]);
        }

        foreach ($commands as $command) {
            try {
                // A client built with exceptions off returns the error instead.
                $refused = $this->client()->executeCommand($command) instanceof ErrorInterface;
            } catch (ServerException) {
                $refused = true;
            }

            if ($refused) {
                $this->trimRefused = true;

                return;
            }
        }
    }

    /**
     * Determine whether reads still sweep the keyspace with SCAN.
     *
     * On by default, and when the key is missing from an older published
     * config, so an upgrade never loses data written by a node on the
     * previous release, which writes no index.
     */
    protected function scanFallback(): bool
    {
        return (bool) ($this->config['scan_fallback'] ?? true);
    }

    /**
     * The members of one set, as strings.
     *
     * @return list<string>
     */
    protected function members(string $key): array
    {
        return array_values(array_map('strval', $this->client()->smembers($key)));
    }

    /**
     * The members of every given set in a single pipelined round trip.
     *
     * @param  list<string>  $keys
     * @return list<list<string>>
     */
    protected function pipelinedMembers(array $keys): array
    {
        $results = $this->client()->pipeline(function (ClientContextInterface $pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->smembers($key);
            }
        });

        if (! is_array($results)) {
            return [];
        }

        $sets = [];

        foreach (array_values($results) as $result) {
            $sets[] = is_array($result) ? array_values(array_map('strval', $result)) : [];
        }

        return $sets;
    }

    /**
     * Read every given key's hash in a single pipelined round trip.
     *
     * Ordering is what makes this usable: predis returns one reply per queued
     * command, in the order queued, so index i of the result belongs to key i.
     *
     * @param  list<string>  $keys
     * @return list<array<array-key, mixed>>
     */
    protected function pipelinedHashes(array $keys): array
    {
        $results = $this->client()->pipeline(function (ClientContextInterface $pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->hgetall($key);
            }
        });

        if (! is_array($results)) {
            return [];
        }

        $hashes = [];

        foreach (array_values($results) as $result) {
            $hashes[] = is_array($result) ? $result : [];
        }

        return $hashes;
    }

    /**
     * The keys to read for a channel of an application, one per node.
     *
     * @return list<string>
     */
    protected function nodeKeys(string $channel, ?string $appId): array
    {
        $keys = [];
        $appId = $this->appId($appId);

        foreach ($this->keysMatching($this->keys->scanPattern($appId, $channel)) as $key) {
            // The scan pattern is a glob prefix: `presence-room.7:x` also matches
            // the pattern for `presence-room.7`, so confirm the channel exactly.
            if ($this->keys->channelFromKey($appId, $key) !== $channel) {
                continue;
            }

            $keys[$this->keys->nodeFromKey($key)] = $key;
        }

        return array_values($keys);
    }

    /**
     * Resolve the application id to read, falling back to the sole app.
     */
    protected function appId(?string $appId): string
    {
        if ($appId !== null && $appId !== '') {
            return $appId;
        }

        return $this->defaultAppId ??= $this->soleApplicationId();
    }

    /**
     * The id of the only configured application.
     *
     * @throws InvalidArgumentException when the server does not have exactly one.
     */
    protected function soleApplicationId(): string
    {
        $applications = $this->applications?->all();

        $application = $applications !== null && $applications->count() === 1
            ? $applications->first()
            : null;

        if ($application !== null) {
            return $application->id();
        }

        throw new InvalidArgumentException(
            'The roster could not resolve a default application. Pass the application id explicitly, for example $roster->users($channel, $appId).',
        );
    }

    /**
     * Read the HGETALL of every given key in a single round trip.
     *
     * The per-channel readers used to walk the node keys one blocking HGETALL
     * at a time, which cost one round trip per node on every call while
     * {@see snapshot()} was already reading its keys in one pipelined batch.
     * They now share that batch, so a read costs one sweep and one round trip
     * whatever the node count is.
     *
     * @param  list<string>  $keys
     * @return list<array<array-key, mixed>>
     */
    protected function hashes(array $keys): array
    {
        return $keys === [] ? [] : $this->pipelinedHashes($keys);
    }

    /**
     * Collect every key matching a pattern with a non-blocking SCAN sweep.
     *
     * @return list<string>
     */
    protected function keysMatching(string $pattern): array
    {
        $client = $this->client();
        $cursor = '0';
        $keys = [];

        do {
            [$cursor, $batch] = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);

            foreach ($batch as $key) {
                $keys[] = $key;
            }
        } while ((string) $cursor !== '0');

        return $keys;
    }

    /**
     * Resolve the predis client, building it on first use.
     */
    protected function client(): Client
    {
        return $this->client ??= new Client(RosterConnection::parameters($this->config));
    }
}
