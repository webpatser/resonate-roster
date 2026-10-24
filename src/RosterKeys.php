<?php

namespace Webpatser\ResonateRoster;

/**
 * The roster key schema, shared by the write side (the plugin) and the read
 * side (the RoomRoster) so the two can never drift.
 *
 * A presence channel C of application A on node N is stored at
 * "{prefix}:{A}:{C}:{N}", a Redis hash of socket id => presence user id. Each
 * node owns only its own key, so a dead node's membership expires by TTL
 * without a live node holding it open.
 *
 * The application segment is what keeps two applications that happen to use
 * the same channel name (both serving a "presence-lobby", say) in separate
 * hashes. Before 0.3.0 the key was "{prefix}:{C}:{N}" with no application
 * segment, so those two applications shared one hash and each one's heartbeat
 * reconcile deleted the other's members as stale. 1.0 no longer reads that
 * layout at all; see UPGRADE.md for the path from a pre-0.3.0 deployment.
 */
class RosterKeys
{
    /**
     * The key prefix used when none is configured.
     *
     * Published as a constant so a consumer such as
     * webpatser/resonate-webhooks can share this default rather than repeat
     * the literal and silently desync if it ever changes.
     */
    public const DEFAULT_PREFIX = 'roster';

    /**
     * Create a new key schema instance.
     *
     * @param  string  $prefix  Namespace for every roster key.
     */
    public function __construct(
        protected string $prefix = self::DEFAULT_PREFIX,
    ) {
        //
    }

    /**
     * Build the schema from the "resonate-roster" config array.
     *
     * Every reader of roster keys should build its schema this way, so the
     * prefix is configured in exactly one place.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $prefix = $config['key_prefix'] ?? self::DEFAULT_PREFIX;

        return new self(is_string($prefix) ? $prefix : self::DEFAULT_PREFIX);
    }

    /**
     * The configured key prefix.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * The hash key holding one node's membership of a channel of one app.
     */
    public function hashKey(string $appId, string $channel, string $node): string
    {
        return $this->prefix.':'.$appId.':'.$channel.':'.$node;
    }

    /**
     * The SCAN pattern matching every node's key for one channel of one app.
     *
     * The trailing ":*" keeps "presence-foo" from matching "presence-foobar":
     * a node id always follows a literal colon.
     *
     * The application and channel segments are glob-escaped so that
     * metacharacters in either ("* ? [ \") match literally rather than
     * broadening the SCAN sweep.
     */
    public function scanPattern(string $appId, string $channel): string
    {
        return $this->prefix.':'.$this->escapeGlob($appId).':'.$this->escapeGlob($channel).':*';
    }

    /**
     * The SCAN pattern matching every roster key of one application.
     */
    public function appPattern(string $appId): string
    {
        return $this->prefix.':'.$this->escapeGlob($appId).':*';
    }

    /**
     * Extract the channel name from a full roster key of one application.
     *
     * A key is "{prefix}:{appId}:{channel}:{node}". The node id never contains
     * a colon, so the channel is everything between the application segment
     * and the final colon, which keeps this correct even when the prefix
     * itself has colons.
     *
     * Returns null when the key does not belong to that application, or has
     * no channel and node segments at all.
     */
    public function channelFromKey(string $appId, string $key): ?string
    {
        $head = $this->prefix.':'.$appId.':';

        if (! str_starts_with($key, $head)) {
            return null;
        }

        $rest = substr($key, strlen($head));

        $lastColon = strrpos($rest, ':');

        return $lastColon === false || $lastColon === 0 ? null : substr($rest, 0, $lastColon);
    }

    /**
     * Extract the node id from a full roster key.
     *
     * The node id is colon-free, so it is always the final segment.
     */
    public function nodeFromKey(string $key): string
    {
        $lastColon = strrpos($key, ':');

        return $lastColon === false ? $key : substr($key, $lastColon + 1);
    }

    /**
     * Escape Redis glob metacharacters so a value matches literally inside a
     * SCAN MATCH pattern.
     *
     * Redis glob uses a backslash to escape the special characters "* ? [ \".
     * The backslash itself is escaped first so it is not consumed twice.
     */
    protected function escapeGlob(string $value): string
    {
        return addcslashes($value, '\\*?[');
    }

    /**
     * A stable, colon-free identifier for the current Resonate process.
     *
     * Colon-free is a requirement, not a nicety: it is what lets
     * {@see channelFromKey()} and {@see nodeFromKey()} split a key
     * unambiguously.
     */
    public static function nodeId(): string
    {
        $host = gethostname() ?: 'node';

        return str_replace(':', '-', $host).'-'.getmypid();
    }
}
