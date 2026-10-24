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

A presence channel `C` of application `A` on a Resonate node `N` is stored as a Redis **hash**:

```
{prefix}:{A}:{C}:{N}    field = socket id    value = presence user id
```

For example, two browser tabs from one user plus a second user, all on one node, look like:

```
roster:481523:presence-chat.42:web-1-9001
  3919c8.41 => "7"      # user 7, tab one
  3919c8.88 => "7"      # user 7, tab two
  4a02f1.12 => "31"     # user 31
```

Two things are deliberate here:

**Each node owns only its own key.** There is no shared set that several nodes write into, which is what makes TTL-based self-healing correct (see below).

**The application is part of the key.** A Resonate process can serve several applications, and nothing stops two of them from using the same channel name. Without the application segment they would share one hash: membership would merge, and each application's heartbeat would delete the other's members as stale. Keys written before 0.3.0 have no application segment and are not read by 1.0; see [UPGRADE.md](UPGRADE.md).

A reader resolves a channel by `SCAN`-ing `{prefix}:{A}:{C}:*`, reading each node's hash, and merging:

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

2. **A heartbeat tick** (`heartbeat_interval`, default 30s) is authoritative. On each tick the plugin walks every presence channel it has seen, per application, reads the **live connections** from Resonate, and rewrites that application's key to match: it adds anything a missed `onSubscribe` left out, removes anything a missed `onClose` left behind, refreshes the TTL, and forgets channels that have emptied. Because every key it writes is built from the application it is reconciling, the pass cannot reach another application's members, and it never touches a pre-0.3.0 key.

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

### Reading the whole application at once

A dashboard or a periodic sweep usually wants every channel, not one. Each per-channel call is its own full keyspace `SCAN`, so asking "who is in it and how many connections" for C channels costs `1 + 2C` sweeps: at 500 channels that is roughly 1000 sweeps per poll, against the Redis your socket server is also using.

`snapshot()` answers all of it in one sweep plus a single pipelined batch of `HGETALL`s, because that sweep already yields everything the answer needs (the hash values are the presence user ids, the field count is the connection count):

```php
$roster->snapshot();          // sole application
$roster->snapshot('481523');  // a named one

// [
//     'presence-chat.42' => ['users' => ['7', '31'], 'connections' => 3],
//     'updates'          => ['users' => [], 'connections' => 12],
// ]
```

The cost does not grow with the number of channels. Consumers such as `webpatser/resonate-pulse` gather their metrics through it.

### Which application?

A roster belongs to one application, so every read method takes the application id as an optional final argument:

```php
$roster->users('presence-chat.42', '481523');
$roster->isOnline('presence-chat.42', '7', '481523');
$roster->occupiedChannels('481523');
```

Leave it out and the **sole configured application** is used, so a single-app server (the common case) keeps calling exactly as it did before 0.3.0. On a server with several applications, omitting it throws an `InvalidArgumentException`: a channel name alone does not identify a roster there, and guessing would hand back another application's members.

## Configuration

| Key | Default | Purpose |
|-----|---------|---------|
| `connection` | `REDIS_*` env | The Redis server. The plugin and the reader **must** point at the same server and database. |
| `key_prefix` | `roster` | Namespace for every roster key. Avoid colons in the prefix. |
| `ttl` | `90` | Seconds each node's key lives; refreshed on every heartbeat. |
| `heartbeat_interval` | `30` | Seconds between reconcile ticks. Keep it well below `ttl`. |
| `track` | `presence` | `presence` mirrors only presence channels; `all` mirrors every channel type, so the roster also answers "how many connections does this channel have". |

Override any of these per environment with `RESONATE_ROSTER_*` variables (see the published config file).

The `connection` block takes Laravel's own Redis connection keys and is handed to fledge-fiber verbatim, so `scheme` (`tcp`, `tls`/`rediss`, `unix`), `read_timeout`, `name`, `tcp_keepalive` and the retry keys all work alongside host, port, credentials and database. Set `scheme` to `tls` (or point `url` at a `rediss://` address) for a managed Redis that requires encryption.

## Upgrading

See [UPGRADE.md](UPGRADE.md). Coming from a release older than 0.3.0, upgrade through 0.7.x first: 1.0 no longer reads the pre-0.3.0 key layout.

## Notes and caveats

- **One Redis, both sides.** The plugin (fledge-fiber async client) and `RoomRoster` (predis) read the same `connection` block, so they must point at the same server and database. This is the single source of truth; do not split it.
- **Presence channels only.** Only `presence-*` channels are mirrored. Public and private channels are ignored.
- **Distinct-user semantics.** `users()` deduplicates by the presence `user_id`, so a user on several tabs or several nodes counts once. `sockets()` does not deduplicate.
- **Eventually consistent.** A missed lifecycle hook is corrected within one `heartbeat_interval`; a hard node crash clears within one `ttl`.
- **One roster per application.** Keys carry the application id, so two applications serving a channel of the same name keep separate membership, and a heartbeat can only ever rewrite its own application's key.
- **Not Redis Cluster safe.** Every read discovers node keys with `SCAN`, which on Redis Cluster only walks the node it is sent to. Point the roster at a single Redis (a primary with replicas is fine).
- **The roster is product-agnostic.** It mirrors any presence channel. "Chat rooms" are just `presence-chat.{id}` channels; nothing here is chat-specific.

## Requirements

- PHP 8.5+
- Resonate 1.0+
- A Redis server reachable from both the Resonate process and your Laravel app

## Testing

```bash
composer test
```

Tests that touch Redis expect a server on `127.0.0.1:6379` and use database 15; they skip cleanly when no Redis is reachable.

## License

MIT. See [LICENSE](LICENSE).
