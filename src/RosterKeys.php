<?php

namespace Webpatser\ResonateRoster;

/**
 * The roster key schema, shared by the write side (the plugin) and the read
 * side (the RoomRoster) so the two can never drift.
 *
 * A presence channel C on node N is stored at "{prefix}:{C}:{N}", a Redis
 * hash of socket id => presence user id. Each node owns only its own key, so
 * a dead node's membership expires by TTL without a live node holding it open.
 */
class RosterKeys
{
    /**
     * Create a new key schema instance.
     */
    public function __construct(protected string $prefix = 'roster')
    {
        //
    }

    /**
     * The hash key holding one node's membership of a channel.
     */
    public function hashKey(string $channel, string $node): string
    {
        return $this->prefix.':'.$channel.':'.$node;
    }

    /**
     * The SCAN pattern matching every node's key for a single channel.
     *
     * The trailing ":*" keeps "presence-foo" from matching "presence-foobar":
     * a node id always follows a literal colon.
     */
    public function scanPattern(string $channel): string
    {
        return $this->prefix.':'.$channel.':*';
    }

    /**
     * The SCAN pattern matching every roster key for every channel.
     */
    public function allPattern(): string
    {
        return $this->prefix.':*';
    }

    /**
     * Extract the channel name from a full roster key.
     *
     * A key is "{prefix}:{channel}:{node}". The node id never contains a
     * colon, so the channel is everything between the prefix and the final
     * colon, which keeps this correct even when the prefix itself has colons.
     */
    public function channelFromKey(string $key): string
    {
        $rest = substr($key, strlen($this->prefix) + 1);

        $lastColon = strrpos($rest, ':');

        return $lastColon === false ? $rest : substr($rest, 0, $lastColon);
    }

    /**
     * A stable, colon-free identifier for the current Resonate process.
     *
     * Colon-free is a requirement, not a nicety: it is what lets
     * {@see channelFromKey()} split a key unambiguously.
     */
    public static function nodeId(): string
    {
        $host = gethostname() ?: 'node';

        return str_replace(':', '-', $host).'-'.getmypid();
    }
}
