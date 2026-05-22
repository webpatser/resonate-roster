<?php

namespace Webpatser\ResonateRoster;

use Predis\Client;

/**
 * The read side of the roster.
 *
 * A plain synchronous query API for the host app, a billing meter, or any
 * other backend code: it answers "who is online in channel C" by reading the
 * per-node Redis hashes the {@see RedisRosterPlugin} writes, with no metrics
 * round-trip to the socket server.
 *
 * It uses predis (pure PHP, no extension required), since the consuming app
 * is an ordinary Laravel request, not the fiber runtime.
 */
class RoomRoster
{
    /**
     * The lazily built predis client.
     */
    protected ?Client $client = null;

    /**
     * The roster key schema.
     */
    protected RosterKeys $keys;

    /**
     * Create a new roster reader.
     *
     * @param  array<string, mixed>  $config  The "resonate-roster" config array.
     */
    public function __construct(protected array $config)
    {
        $this->keys = new RosterKeys($config['key_prefix'] ?? 'roster');
    }

    /**
     * The distinct presence user ids online in a channel.
     *
     * @return list<string>
     */
    public function users(string $channel): array
    {
        $users = [];

        foreach ($this->hashes($this->keys->scanPattern($channel)) as $hash) {
            foreach ($hash as $userId) {
                if ($userId !== '') {
                    $users[$userId] = true;
                }
            }
        }

        return array_keys($users);
    }

    /**
     * The socket ids online in a channel.
     *
     * @return list<string>
     */
    public function sockets(string $channel): array
    {
        $sockets = [];

        foreach ($this->hashes($this->keys->scanPattern($channel)) as $hash) {
            foreach (array_keys($hash) as $socketId) {
                $sockets[$socketId] = true;
            }
        }

        return array_keys($sockets);
    }

    /**
     * The number of distinct users online in a channel.
     */
    public function userCount(string $channel): int
    {
        return count($this->users($channel));
    }

    /**
     * The number of sockets online in a channel.
     */
    public function socketCount(string $channel): int
    {
        return count($this->sockets($channel));
    }

    /**
     * The number of connections in a channel.
     *
     * An alias of {@see socketCount()} that reads more naturally for
     * non-presence channels, where "sockets" and "connections" are the same
     * thing and there is no presence user to speak of.
     */
    public function connectionCount(string $channel): int
    {
        return $this->socketCount($channel);
    }

    /**
     * Determine whether a channel has at least one connection.
     */
    public function isOccupied(string $channel): bool
    {
        return $this->keysMatching($this->keys->scanPattern($channel)) !== [];
    }

    /**
     * Determine whether a user is online in a channel.
     */
    public function isOnline(string $channel, string $userId): bool
    {
        return in_array($userId, $this->users($channel), true);
    }

    /**
     * Every channel that currently has at least one member.
     *
     * @return list<string>
     */
    public function occupiedChannels(): array
    {
        $channels = [];

        foreach ($this->keysMatching($this->keys->allPattern()) as $key) {
            $channels[$this->keys->channelFromKey($key)] = true;
        }

        return array_keys($channels);
    }

    /**
     * Yield the HGETALL of every key matching a pattern.
     *
     * @return iterable<array<string, string>>
     */
    protected function hashes(string $pattern): iterable
    {
        foreach ($this->keysMatching($pattern) as $key) {
            yield $this->client()->hgetall($key);
        }
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
        return $this->client ??= new Client($this->parameters());
    }

    /**
     * Translate the connection config into predis connection parameters.
     *
     * @return array<string, mixed>|string
     */
    protected function parameters(): array|string
    {
        $server = $this->config['connection'] ?? [];

        if (! empty($server['url'])) {
            return $server['url'];
        }

        $parameters = [
            'scheme' => 'tcp',
            'host' => $server['host'] ?? '127.0.0.1',
            'port' => (int) ($server['port'] ?? 6379),
            'database' => (int) ($server['database'] ?? 0),
        ];

        if (! empty($server['username'])) {
            $parameters['username'] = $server['username'];
        }

        if (! empty($server['password'])) {
            $parameters['password'] = $server['password'];
        }

        if (! empty($server['timeout'])) {
            $parameters['timeout'] = (float) $server['timeout'];
        }

        return $parameters;
    }
}
