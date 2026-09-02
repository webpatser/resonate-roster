<?php

namespace Webpatser\ResonateRoster;

/**
 * Translates the roster's `connection` config block into predis parameters.
 *
 * Shared by every synchronous reader in this package ({@see RoomRoster} and
 * the migrate command) so there is one interpretation of the config block.
 */
class RosterConnection
{
    /**
     * Build predis connection parameters from the "resonate-roster" config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|string
     */
    public static function parameters(array $config): array|string
    {
        $server = $config['connection'] ?? [];

        if (! is_array($server)) {
            $server = [];
        }

        if (! empty($server['url'])) {
            return (string) $server['url'];
        }

        $parameters = [
            'scheme' => self::predisScheme($server),
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

    /**
     * The predis scheme for a connection block.
     *
     * Predis names TLS "tls"; the config accepts the `rediss` spelling too,
     * because that is what a managed provider publishes and what the async
     * side understands. Anything else falls through to plain tcp, so a
     * connection block carrying no scheme behaves exactly as it did before
     * the key existed.
     *
     * @param  array<string, mixed>  $server
     */
    protected static function predisScheme(array $server): string
    {
        $scheme = strtolower((string) ($server['scheme'] ?? 'tcp'));

        return match ($scheme) {
            'tls', 'rediss' => 'tls',
            'unix' => 'unix',
            default => 'tcp',
        };
    }
}
