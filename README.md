# Resonate Roster

A Redis room roster for [Resonate](https://github.com/webpatser/resonate). It mirrors every presence channel into Redis so "who is online" becomes:

- **restart-safe**: it survives a Resonate reload, instead of being rebuilt only as clients reconnect;
- **multi-node-correct**: one shared truth across nodes, instead of per-node memory fragments;
- **backend-queryable**: readable directly from your Laravel app or a billing meter, with no metrics round-trip.

## The problem it solves

Resonate keeps presence channel membership in process memory, per node (`ArrayChannelManager` / `ArrayChannelConnectionManager`). That has two consequences:

1. **It is lost on a restart or reload.** After a `resonate:reload` the membership of `presence-chat.42` is empty until clients happen to reconnect and re-subscribe.
2. **It is not shared across nodes.** With `REVERB_SCALING_ENABLED=true`, Redis is only a pub/sub message bus between nodes. Each node still knows only its own connections. "Who is online across the cluster" is computed on demand by a metrics round-trip, not stored anywhere.

So there is no key you can read to answer "who is online in chat X". This package adds one.

## How it works

### The data model

A presence channel `C` on a Resonate node `N` is stored as a Redis **hash**:

```
{prefix}:{C}:{N}        field = socket id    value = presence user id
```

For example, two browser tabs from one user plus a second user, all on one node, look like:

```
roster:presence-chat.42:web-1-9001
  3919c8.41 => "7"      # user 7, tab one
  3919c8.88 => "7"      # user 7, tab two
  4a02f1.12 => "31"     # user 31
```

The key point: **each node owns only its own key.** There is no shared set that several nodes write into. That is a deliberate choice, because it is what makes TTL-based self-healing correct (see below).

A reader resolves a channel by `SCAN`-ing `{prefix}:{C}:*`, reading each node's hash, and merging:

- **sockets online** = every field across the hashes;
- **users online** = the distinct set of values across the hashes (so a user with three tabs counts once).

### The write side: `RedisRosterPlugin`

The plugin runs **inside** the Resonate process as a registered server plugin. Because Resonate runs on a fiber runtime, its Redis writes suspend the calling fiber instead of blocking the event loop.

It reacts to three connection lifecycle events:

| Event | What it does |
|-------|--------------|
| `onSubscribe` | A connection joined a `presence-*` channel: `HSET` its socket id and user id into this node's key, refresh the TTL, and record the channel on the connection's own state bag. |
| `onUnsubscribe` | A connection left a channel with an explicit `pusher:unsubscribe`: `HDEL` its socket id from this node's key. |
| `onClose` | A connection's socket closed: `HDEL` its socket id from every channel recorded on its state bag. |

`onClose` reads the channel list back from the **connection's state bag**, not from the channel manager. This is necessary: Resonate strips a connection from every channel *before* the close hook fires, so by the time `onClose` runs the manager no longer knows which channels the connection held. The plugin records them on subscribe precisely so it can clean them up on close.

### The heartbeat: self-healing

The lifecycle hooks are the fast path, but they are not the source of truth. A node can crash without ever firing `onClose`, leaving stale entries behind. Two mechanisms fix that:

1. **Every key carries a TTL** (`ttl`, default 90s). Because each node owns its own key, a dead node's key is refreshed by nobody and simply expires. A live node never keeps a dead node's entries alive, which is exactly why the per-node key layout matters.

2. **A heartbeat tick** (`heartbeat_interval`, default 30s) is authoritative. On each tick the plugin walks every presence channel it has seen, reads the **live connections** from Resonate, and rewrites this node's key to match: it adds anything a missed `onSubscribe` left out, removes anything a missed `onClose` left behind, refreshes the TTL, and forgets channels that have emptied.

So the roster is eventually consistent within one heartbeat, and worst-case stale data clears within one TTL window.

### The read side: `RoomRoster`

`RoomRoster` runs in your **Laravel app** (an ordinary synchronous request, not the fiber runtime), so it reads Redis over [predis](https://github.com/predis/predis). It shares the `RosterKeys` schema with the plugin, so the two can never disagree about where data lives.

```
Resonate process                         Laravel app
┌─────────────────────────┐              ┌────────────────────────┐
│ RedisRosterPlugin        │   writes     │ RoomRoster             │
│  onSubscribe/Unsub/Close │ ───────────► │  users(), userCount(), │
│  heartbeat reconcile     │   Redis      │  isOnline(), ...       │
│  (fledge-fiber async)    │ ◄─────────── │  (predis, synchronous) │
└─────────────────────────┘    reads      └────────────────────────┘
```

## Installation

```bash
composer require webpatser/resonate-roster
```

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=resonate-roster-config
```

## Registering the plugin

Add the plugin to the `plugins` array of your server in `config/reverb.php`:

```php
'servers' => [
    'reverb' => [
        // ...
        'plugins' => [
            \Webpatser\ResonateRoster\RedisRosterPlugin::class,
        ],
    ],
],
```

Restart Resonate (`php artisan resonate:start`, or `resonate:reload` for a zero-downtime swap) to load it.

## Reading the roster

Resolve `RoomRoster` from the container anywhere in your app:

```php
use Webpatser\ResonateRoster\RoomRoster;

$roster = app(RoomRoster::class);

$roster->users('presence-chat.42');         // ['7', '31'] - distinct user ids
$roster->userCount('presence-chat.42');     // 2
$roster->sockets('presence-chat.42');       // every socket id
$roster->socketCount('presence-chat.42');   // 3
$roster->isOnline('presence-chat.42', '7'); // true
$roster->occupiedChannels();                // every channel with members
```

A billing meter that needs to know whether a chat is still occupied can ask `userCount('presence-chat.42')` directly, with no call into the socket server.

## Configuration

| Key | Default | Purpose |
|-----|---------|---------|
| `connection` | `REDIS_*` env | The Redis server. The plugin and the reader **must** point at the same server and database. |
| `key_prefix` | `roster` | Namespace for every roster key. Avoid colons in the prefix. |
| `ttl` | `90` | Seconds each node's key lives; refreshed on every heartbeat. |
| `heartbeat_interval` | `30` | Seconds between reconcile ticks. Keep it well below `ttl`. |

Override any of these per environment with `RESONATE_ROSTER_*` variables (see the published config file).

## Notes and caveats

- **One Redis, both sides.** The plugin (fledge-fiber async client) and `RoomRoster` (predis) read the same `connection` block, so they must point at the same server and database. This is the single source of truth; do not split it.
- **Presence channels only.** Only `presence-*` channels are mirrored. Public and private channels are ignored.
- **Distinct-user semantics.** `users()` deduplicates by the presence `user_id`, so a user on several tabs or several nodes counts once. `sockets()` does not deduplicate.
- **Eventually consistent.** A missed lifecycle hook is corrected within one `heartbeat_interval`; a hard node crash clears within one `ttl`.
- **The roster is product-agnostic.** It mirrors any presence channel. "Chat rooms" are just `presence-chat.{id}` channels; nothing here is chat-specific.

## Requirements

- PHP 8.5+
- Resonate 0.4+
- A Redis server reachable from both the Resonate process and your Laravel app

## Testing

```bash
composer test
```

Tests that touch Redis expect a server on `127.0.0.1:6379` and use database 15; they skip cleanly when no Redis is reachable.

## License

MIT. See [LICENSE](LICENSE).
