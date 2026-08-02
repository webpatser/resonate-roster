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

**The application is part of the key.** A Resonate process can serve several applications, and nothing stops two of them from using the same channel name. Without the application segment they would share one hash: membership would merge, and each application's heartbeat would delete the other's members as stale. Keys written before 0.3.0 have no application segment; see [Upgrading to the app-scoped key schema](#upgrading-to-the-app-scoped-key-schema).

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

The cost does not grow with the number of channels, and the result honours the same dual-read window as every other read method, so it stays correct mid-upgrade. Consumers such as `webpatser/resonate-pulse` gather their metrics through it.

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
| `legacy_fallback` | `true` | Whether readers also consult pre-0.3.0 unscoped keys. Keep it on during an upgrade; switch it off once every node is upgraded. |
| `ttl` | `90` | Seconds each node's key lives; refreshed on every heartbeat. |
| `heartbeat_interval` | `30` | Seconds between reconcile ticks. Keep it well below `ttl`. |
| `track` | `presence` | `presence` mirrors only presence channels; `all` mirrors every channel type, so the roster also answers "how many connections does this channel have". |

Override any of these per environment with `RESONATE_ROSTER_*` variables (see the published config file).

## Upgrading to the app-scoped key schema

Before 0.3.0 a roster key was `{prefix}:{C}:{N}`, with no application segment. From 0.3.0 it is `{prefix}:{A}:{C}:{N}`. A naive deploy would orphan every existing key: the new code would look for app-scoped keys, find none, and report every presence channel as empty until traffic repopulated it.

So 0.3.0 ships a **dual-read window**. New writes only ever go to the app-scoped key. Reads resolve a channel **per node**: they take the app-scoped key of every node that has one, and the pre-0.3.0 key of every node that does not. Membership is per node, so this never double counts a socket and never drops a node, which is what makes a rolling deploy of mixed old and new nodes correct in both directions. The window is controlled by `legacy_fallback` (default `true`).

### The procedure

**1. Deploy 0.3.x with `legacy_fallback` left at `true`.**

Deploy the package to your Laravel app and to the Resonate hosts, and upgrade any consumer that reads roster keys in the same step: `webpatser/resonate-webhooks` 0.3+ and `webpatser/resonate-pulse` 0.3+. Do not set `RESONATE_ROSTER_LEGACY_FALLBACK=false` yet.

*What you see:* nothing changes. Nodes still running the old code keep writing unscoped keys, and the new reader serves them through the fallback.

**2. Roll the Resonate nodes, one at a time.**

*What you see:* mid-roll, an upgraded node writes `roster:481523:presence-chat.42:web-1-9001` while a node still to come writes `roster:presence-chat.42:web-2-9001`. Any reader merges both, one per node, so `userCount()` is the true cluster count throughout. Occupancy consumers see no edge: no channel reads as empty, so no spurious `channel_vacated`, and a channel that was already occupied before the upgrade is not re-announced as `channel_occupied` (webhooks treats a pre-upgrade occupied flag as a claim already made).

**3. Pass the application id in host code, if you run several applications.**

A single-app host needs no change: with no id given, the sole configured application is used. A multi-app host must pass the id (`$roster->users($channel, $appId)`), and gets an `InvalidArgumentException` naming the fix if it forgets.

*What you see:* on a multi-app server, this is also the moment merged rooms stop merging. Two applications that shared a `presence-lobby` now report their own members, so a count that used to be inflated drops to the real one.

**4. Once every node runs 0.3.x, clear the leftovers.**

```bash
php artisan resonate-roster:migrate-keys --dry-run   # what is still on the old layout
php artisan resonate-roster:migrate-keys             # rename it into the app-scoped schema
```

The command renames each `{prefix}:{C}:{N}` to `{prefix}:{A}:{C}:{N}`, preserving the TTL, and never overwrites a key the live nodes are already writing. On a single-app server the application is resolved for you; otherwise pass `--app=<id>`. If your applications shared channel names, the leftover keys are merged and cannot be attributed to one application at all: use `--prune` to delete them and let the next heartbeat rewrite the truth.

You can also simply wait: no upgraded node refreshes a pre-0.3.0 key, so they all expire on their own within one `ttl` (default 90s) of the last old node going away.

*What you see:* the command lists each rename, or reports "No pre-0.3.0 roster keys found" when there is nothing left. That report is the green light for the next step.

**5. Close the window.**

Set `RESONATE_ROSTER_LEGACY_FALLBACK=false` and restart (`resonate:reload` works). Reads are now strictly per application, with no extra `SCAN` for legacy keys.

*What you see:* nothing, if step 4 reported clean. Anything still living under a pre-0.3.0 key becomes invisible the moment you do this, which is exactly why step 4 comes first.

**Rolling back** is safe as long as the window is open: leave `legacy_fallback` at `true`, and a node downgraded to 0.2.x writes unscoped keys again that the 0.3.x readers still see.

## Notes and caveats

- **One Redis, both sides.** The plugin (fledge-fiber async client) and `RoomRoster` (predis) read the same `connection` block, so they must point at the same server and database. This is the single source of truth; do not split it.
- **Presence channels only.** Only `presence-*` channels are mirrored. Public and private channels are ignored.
- **Distinct-user semantics.** `users()` deduplicates by the presence `user_id`, so a user on several tabs or several nodes counts once. `sockets()` does not deduplicate.
- **Eventually consistent.** A missed lifecycle hook is corrected within one `heartbeat_interval`; a hard node crash clears within one `ttl`.
- **One roster per application.** Keys carry the application id, so two applications serving a channel of the same name keep separate membership, and a heartbeat can only ever rewrite its own application's key.
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
