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
 * reconcile deleted the other's members as stale.
 *
 * The pre-0.3.0 layout is still built by the legacy* methods. Readers consult
 * it as a fallback for the length of the upgrade window, which is what lets a
 * rolling deploy of mixed old and new nodes report correct membership; see
 * the `legacy_fallback` config flag and the README upgrade section.
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
     * @param  bool  $legacyFallback  Whether readers may fall back to the pre-0.3.0 unscoped keys.
     */
    public function __construct(
        protected string $prefix = self::DEFAULT_PREFIX,
        protected bool $legacyFallback = true,
    ) {
        //
    }

    /**
     * Build the schema from the "resonate-roster" config array.
     *
     * Every reader of roster keys should build its schema this way, so the
     * prefix and the fallback window are configured in exactly one place.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $prefix = $config['key_prefix'] ?? self::DEFAULT_PREFIX;

        return new self(
            is_string($prefix) ? $prefix : self::DEFAULT_PREFIX,
            (bool) ($config['legacy_fallback'] ?? true),
        );
    }

    /**
     * The configured key prefix.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Whether readers should fall back to the pre-0.3.0 unscoped keys.
     */
    public function legacyFallback(): bool
    {
        return $this->legacyFallback;
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
     * no channel and node segments at all (a pre-0.3.0 key, for instance).
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
     * Extract the node id from a full roster key, of either schema.
     *
     * The node id is colon-free, so it is always the final segment.
     */
    public function nodeFromKey(string $key): string
    {
        $lastColon = strrpos($key, ':');

        return $lastColon === false ? $key : substr($key, $lastColon + 1);
    }

    /**
     * The pre-0.3.0 hash key for a channel on a node, with no application.
     *
     * Kept so readers can serve data written by a node that has not been
     * upgraded yet. Nothing writes this key any more.
     */
    public function legacyHashKey(string $channel, string $node): string
    {
        return $this->prefix.':'.$channel.':'.$node;
    }

    /**
     * The SCAN pattern matching every pre-0.3.0 key for a single channel.
     */
    public function legacyScanPattern(string $channel): string
    {
        return $this->prefix.':'.$this->escapeGlob($channel).':*';
    }

    /**
     * The SCAN pattern matching every roster key under the prefix.
     *
     * It matches both schemas, so callers filter the result themselves with
     * {@see legacyChannelFromKey()} or {@see channelFromKey()}.
     */
    public function legacyAllPattern(): string
    {
        return $this->prefix.':*';
    }

    /**
     * Determine whether a key is the pre-0.3.0 key of a known channel.
     *
     * Exact even for a channel name containing colons: the channel is known,
     * so all that is left to check is that a single colon-free node segment
     * follows it. This is what keeps {@see legacyScanPattern()} from adopting
     * an app-scoped key of an application whose id equals the channel name.
     */
    public function isLegacyKeyFor(string $channel, string $key): bool
    {
        $head = $this->prefix.':'.$channel.':';

        if (! str_starts_with($key, $head)) {
            return false;
        }

        $node = substr($key, strlen($head));

        return $node !== '' && ! str_contains($node, ':');
    }

    /**
     * Extract the channel name from a pre-0.3.0 key, or null if not one.
     *
     * A pre-0.3.0 key is "{prefix}:{channel}:{node}", so exactly one colon
     * follows the prefix; an app-scoped key has two and is rejected here.
     * A channel name containing a colon could not be told apart from an
     * app-scoped key by shape alone, but the Pusher protocol does not allow
     * colons in a channel name, so no key the plugin ever wrote has one.
     */
    public function legacyChannelFromKey(string $key): ?string
    {
        $head = $this->prefix.':';

        if (! str_starts_with($key, $head)) {
            return null;
        }

        $rest = substr($key, strlen($head));

        if (substr_count($rest, ':') !== 1) {
            return null;
        }

        $channel = substr($rest, 0, (int) strpos($rest, ':'));

        return $channel === '' ? null : $channel;
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
