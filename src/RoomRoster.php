<?php

namespace Webpatser\ResonateRoster;

use InvalidArgumentException;
use Predis\Client;
use Predis\ClientContextInterface;
use Webpatser\Resonate\Contracts\ApplicationProvider;

/**
 * The read side of the roster.
 *
 * A plain synchronous query API for the host app, a billing meter, or any
 * other backend code: it answers "who is online in channel C of application A"
 * by reading the per-node Redis hashes the {@see RedisRosterPlugin} writes,
 * with no metrics round-trip to the socket server.
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
     * The roster key schema.
     */
    protected RosterKeys $keys;

    /**
     * The sole configured application id, resolved on first use.
     */
    protected ?string $defaultAppId = null;

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

        foreach ($this->hashes($this->nodeKeys($channel, $appId)) as $hash) {
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

        foreach ($this->hashes($this->nodeKeys($channel, $appId)) as $hash) {
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
        return $this->nodeKeys($channel, $appId) !== [];
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
     * While the legacy fallback is on, channels found only under a pre-0.3.0
     * key are listed too. Those keys carry no application, so on a server with
     * several applications they are reported for each of them until the last
     * pre-0.3.0 node is gone; see the README upgrade section.
     *
     * @return list<string>
     */
    public function occupiedChannels(?string $appId = null): array
    {
        $app = $this->appId($appId);

        $channels = [];

        foreach ($this->keysMatching($this->keys->appPattern($app)) as $key) {
            $channel = $this->keys->channelFromKey($app, $key);

            if ($channel !== null) {
                $channels[$channel] = true;
            }
        }

        if ($this->keys->legacyFallback()) {
            foreach ($this->keysMatching($this->keys->legacyAllPattern()) as $key) {
                $channel = $this->keys->legacyChannelFromKey($key);

                if ($channel !== null) {
                    $channels[$channel] = true;
                }
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
     * the same Redis the socket server is using. This is one sweep (two while
     * the legacy fallback window is open) plus a single pipelined batch of
     * HGETALLs, because one sweep already yields everything the answer needs:
     * the hash values are the presence user ids and the field count is the
     * connection count.
     *
     * A channel whose node key exists but holds no members is reported with no
     * users and no connections, exactly as {@see isOccupied()} treats it: Redis
     * drops a hash when its last field goes, so an empty one is a momentary
     * state rather than a lasting one.
     *
     * @return array<string, array{users: list<string>, connections: int}> Keyed by channel name.
     */
    public function snapshot(?string $appId = null): array
    {
        $keys = $this->snapshotKeys($this->appId($appId));

        /** @var array<string, int> $connections */
        $connections = [];

        /** @var array<string, array<string, true>> $users */
        $users = [];

        /** @var list<string> $flat */
        $flat = [];

        /** @var list<string> $owners */
        $owners = [];

        foreach ($keys as $channel => $nodeKeys) {
            $channel = (string) $channel;

            $connections[$channel] = 0;

            foreach ($nodeKeys as $key) {
                $flat[] = $key;
                $owners[] = $channel;
            }
        }

        if ($flat === []) {
            return [];
        }

        foreach ($this->pipelinedHashes($flat) as $index => $hash) {
            $channel = $owners[$index] ?? null;

            if ($channel === null) {
                continue;
            }

            $connections[$channel] += count($hash);

            // The hash is socket id => presence user id. A blank user id is a
            // non-presence member, which counts as a connection but not as a
            // distinct user, the same way users() treats it.
            foreach ($hash as $userId) {
                if (is_string($userId) && $userId !== '') {
                    $users[$channel][$userId] = true;
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
     * The dual-read window {@see nodeKeys()} implements for one channel,
     * applied to a whole application in one pass: the app-scoped key of every
     * node that has one, plus the pre-0.3.0 key of every node that has none.
     * Membership is per node, so this can neither double count a socket nor
     * drop a node, and it costs the same two sweeps at any channel count.
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

        if ($this->keys->legacyFallback()) {
            foreach ($this->keysMatching($this->keys->legacyAllPattern()) as $key) {
                $channel = $this->keys->legacyChannelFromKey($key);

                if ($channel !== null) {
                    $keys[$channel][$this->keys->nodeFromKey($key)] ??= $key;
                }
            }
        }

        return $keys;
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
     * The keys to read for a channel, one per node, app-scoped key first.
     *
     * This is the dual-read window in one place: a node that writes the
     * app-scoped key is served from it, and a node that has not been upgraded
     * yet is served from its pre-0.3.0 key. Membership is per node, so taking
     * the app-scoped key of every node plus the legacy key of every node that
     * has none can never double count a socket, and never drops a node.
     *
     * @return list<string>
     */
    protected function nodeKeys(string $channel, ?string $appId): array
    {
        $keys = [];

        foreach ($this->keysMatching($this->keys->scanPattern($this->appId($appId), $channel)) as $key) {
            $keys[$this->keys->nodeFromKey($key)] = $key;
        }

        if ($this->keys->legacyFallback()) {
            foreach ($this->keysMatching($this->keys->legacyScanPattern($channel)) as $key) {
                if (! $this->keys->isLegacyKeyFor($channel, $key)) {
                    continue;
                }

                $keys[$this->keys->nodeFromKey($key)] ??= $key;
            }
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
