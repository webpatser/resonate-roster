# Upgrade Guide

## Unreleased: index sets replace the keyspace SCAN

The roster hashes (`{prefix}:{A}:{C}:{N}`, socket id => presence user id) are unchanged. Next to them the plugin now keeps two index sets, and `RoomRoster` can read through them instead of sweeping the whole Redis keyspace with `SCAN` on every call.

### New keys

| Key | Type | Members | TTL |
|-----|------|---------|-----|
| `{prefix}-channels:{A}` (`RosterKeys::channelIndexKey($appId)`) | set | channel names of application A that hold a roster hash | the roster `ttl` |
| `{prefix}-nodes:{A}:{C}` (`RosterKeys::nodeIndexKey($appId, $channel)`) | set | ids of the nodes holding a hash for channel C of application A | the roster `ttl` |

Who writes and trims them:

- **Join and heartbeat.** After a node writes its hash on subscribe, and for every channel it still holds on each heartbeat, one Lua script adds the node to the node index and the channel to the channel index and extends both TTLs to this node's `ttl`. The script reads each set's remaining TTL first and only ever lengthens it, so a node configured with a smaller `ttl` never cuts a shared set short of another node's hash (it does this with `TTL` plus `EXPIRE` rather than `EXPIRE ... GT`, which would leave a freshly created set without any TTL, and it works on every Redis version). Every live node keeps both sets alive this way.
- **Leave.** On unsubscribe and close, one Lua script removes the socket from the hash; once the hash is empty it removes the node from the node index, and once the node index is empty it removes the channel from the channel index. The heartbeat does the same in one script when a channel it tracks has emptied.
- **A node that dies without cleaning up.** Its hashes expire after `ttl`, but its index entries stay while live nodes keep refreshing the sets. `RoomRoster` skips an indexed node whose hash is gone and an indexed channel without a live hash, so it never reports a member or a channel whose hash has expired, and it removes those entries with a script that re-checks inside Redis that the hash is still missing (or the node index still empty) before it deletes. When no live node refreshes a set any more, the set itself expires after `ttl`. The trim is sent as its own command, outside the read's pipeline. When the server refuses it (a read-only replica, an ACL user without `EVAL`, scripting disabled, any error reply), that `RoomRoster` instance stops trying for the rest of its life, keeps its connection, and still returns correct results, because the read skips such entries anyway; the entries then stay in the sets until a reader that may write trims them or the set expires.

The sets sit outside the `{prefix}:` namespace, so no `SCAN` pattern of the roster (`{prefix}:{A}:*`) matches them.

### Rolling deploy in two steps

A node on the previous release writes hashes and no index entries. The new `scan_fallback` config key (`RESONATE_ROSTER_SCAN_FALLBACK`, default `true`, and treated as `true` when a published config file lacks the key) decides what `RoomRoster` reads:

- `true`: the keyspace `SCAN` exactly as before. `SCAN` finds every hash, from old and new nodes alike, so the index adds nothing and is not consulted. The cost is the old one: a full keyspace sweep (about one round trip per 100 keys in the database) on every per-channel read, every `occupiedChannels()` and every `snapshot()`.
- `false`: only the index sets. A per-channel read is one `SMEMBERS` and one pipelined `HGETALL`; `snapshot()` and `occupiedChannels()` are three round trips (`SMEMBERS` of the channel index, a pipelined `SMEMBERS` of the node indexes, a pipelined `HGETALL`). No `SCAN`. Hashes written by a node that keeps no index are not seen.

1. Deploy this release everywhere with `scan_fallback` left at `true`: the plugin on every Resonate node, and the apps that read with `RoomRoster`. Nothing is lost while old and new nodes run side by side.
2. Wait until the last Resonate node on the previous release has stopped, and then at least one `heartbeat_interval` more, so every upgraded node has indexed what it holds. Then set `RESONATE_ROSTER_SCAN_FALLBACK=false` in the reading apps and restart them. If the switch must cause no visible change at all, wait one full `ttl` after the last old node stopped instead: its hashes linger as ghosts for up to `ttl`, `SCAN` still reports those members, and the index never does.

If you run with Redis ACLs, the plugin needs `HSET`, `HDEL`, `EXPIRE`, `TTL`, `SADD`, `SREM`, `SCARD` and `EVAL`/`EVALSHA`; a reader with `scan_fallback` off needs `SMEMBERS` and `HGETALL`, plus `EVAL` if it should trim stale index entries (without it the reader still works, see above).

### webpatser/resonate-pulse

The Pulse recorder reads through `RoomRoster::snapshot()`, so it needs no code change. Its app must set `RESONATE_ROSTER_SCAN_FALLBACK=false` (step 2) to stop the keyspace `SCAN` on every recording. It still reads every hash with `HGETALL`: a counts-only read path (`HLEN` per indexed hash) is a deferred follow-up and does not exist in this release.

### External tooling reading the keys

- Prefer `RoomRoster`. Code that reads the keys itself can list an application's channels with `SMEMBERS {prefix}-channels:{A}`, a channel's nodes with `SMEMBERS {prefix}-nodes:{A}:{C}`, and build each hash key with `RosterKeys::hashKey($appId, $channel, $node)`.
- Index entries can be stale: always treat a missing hash as "no members on that node", and a channel without any live hash as unoccupied.
- Until step 2 is done, the index does not list hashes written by nodes on the previous release; a reader that must see those keeps using `SCAN` over `{prefix}:{A}:*`, which still never matches the index sets.
- Redis Cluster is still not supported: a roster hash and its index sets carry no common hash tag and so can live in different slots, and the scripts touch both.

## From 0.7.x to 1.0

### Requirements

- `webpatser/resonate` `^1.0` (was `^0.7`).

### The pre-0.3.0 key fallback is gone

Before 0.3.0 a roster key was `{prefix}:{C}:{N}`, with no application segment. From 0.3.0 it is `{prefix}:{A}:{C}:{N}`, and 0.3.x through 0.7.x kept reading the old layout during a `legacy_fallback` window so a rolling deploy stayed correct. 1.0 removes that window:

- The `legacy_fallback` config key and `RESONATE_ROSTER_LEGACY_FALLBACK` are no longer read. Remove them from a published `config/resonate-roster.php` and your environment.
- `RosterKeys::legacyFallback()`, `legacyHashKey()`, `legacyScanPattern()`, `legacyAllPattern()`, `isLegacyKeyFor()` and `legacyChannelFromKey()` are removed, and the `RosterKeys` constructor no longer takes a second argument.
- The `resonate-roster:migrate-keys` command is removed.
- Readers (`RoomRoster`, and consumers such as `webpatser/resonate-webhooks` 1.0) only consult app-scoped keys. A pre-0.3.0 key is invisible to them.

**Coming from a release older than 0.3.0? Upgrade through 0.7.x first**, and finish the key migration there before going to 1.0:

1. Deploy 0.7.x with `legacy_fallback` left at `true`, together with the roster consumers you run (`webpatser/resonate-webhooks`, `webpatser/resonate-pulse`). Readers merge both layouts per node, so a rolling deploy reports correct membership throughout.
2. Roll every Resonate node onto 0.7.x.
3. On a multi-app server, pass the application id to every `RoomRoster` call; with no id the sole configured application is used, and a multi-app server throws instead of guessing.
4. Clear the leftovers: `php artisan resonate-roster:migrate-keys --dry-run`, then `php artisan resonate-roster:migrate-keys` (or `--app=<id>`, or `--prune` when applications shared channel names). You can also wait: no upgraded node refreshes a pre-0.3.0 key, so they expire within one `ttl` of the last old node going away. Continue once the command reports "No pre-0.3.0 roster keys found".
5. Set `RESONATE_ROSTER_LEGACY_FALLBACK=false`, restart, and confirm occupancy still looks right.

Only then upgrade to 1.0. If you were already on 0.3.0 or later with no pre-0.3.0 keys left, there is nothing to do beyond the requirements above.

### Known limitation: not Redis Cluster safe

Key discovery uses `SCAN`. On Redis Cluster a `SCAN` only walks the node it is sent to, so the roster would miss every key held by the other shards. Point the roster at a single Redis server (a primary with replicas is fine); Redis Cluster is not supported in 1.0.
