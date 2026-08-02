# Changelog

All notable changes to `webpatser/resonate-roster` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Roster keys carry the application id. A key is now `{prefix}:{appId}:{channel}:{node}`, the shape `webpatser/resonate-delivery` already uses. Two applications configured in one Resonate process that both serve a channel of the same name (a `presence-lobby` each, say) shared a single Redis hash, so membership merged: `RoomRoster` reported both applications' members as one room, and every consumer reading the same keyspace inherited it (`webpatser/resonate-webhooks` fired occupancy edges against the wrong application, `webpatser/resonate-pulse` summed metrics across applications).
- The heartbeat reconcile pass can no longer delete another application's members. It rebuilt the shared key authoritatively from one application's live connections, which removed the other application's members as stale on every tick. Each pass now only touches keys built from the application it is reconciling, and never touches a pre-0.3.0 key at all.

### Added

- `legacy_fallback` config flag (`RESONATE_ROSTER_LEGACY_FALLBACK`, default `true`): the dual-read window. While it is on, a reader that finds no app-scoped key for a node falls back to that node's pre-0.3.0 unscoped key, so a rolling deploy of mixed old and new nodes keeps reporting correct membership in both directions. Set it to `false` to make reads strictly per application once every node is upgraded.
- `php artisan resonate-roster:migrate-keys`: renames leftover pre-0.3.0 keys into the app-scoped schema, preserving their TTL. `--app=<id>` names the application to attribute them to (a single-app server needs no flag), `--prune` deletes them instead of attributing them (the honest option on a server whose applications shared channel names, where a merged key cannot be attributed at all), and `--dry-run` reports without writing. With no keys left it says so, which is the check to run before closing the window.
- `RoomRoster::snapshot($appId = null)`: every occupied channel of an application in one read, returned as `channel => ['users' => [...], 'connections' => n]`. Gathering C channels through the per-channel methods costs `1 + 2C` full keyspace sweeps (roughly 1000 at 500 channels, on every dashboard poll); a snapshot is one sweep plus a single pipelined batch of `HGETALL`s, because that sweep already yields everything the answer needs. It honours the same dual-read window and the same default-application resolution as the other read methods, and throws the same `InvalidArgumentException` when a multi-app server omits the id. `webpatser/resonate-pulse` 0.3+ gathers its metrics through it instead of scanning the keyspace itself.
- `RoomRoster` takes an optional ready predis client as its third constructor argument, so a host that already owns a connection (or a test that wants to instrument one) can hand it over instead of having one built from the config.
- `RosterKeys::fromConfig()`, `RosterKeys::DEFAULT_PREFIX`, `prefix()`, and `legacyFallback()`, so a consumer such as `webpatser/resonate-webhooks` builds the schema from the roster's own config instead of repeating the prefix literal and drifting from it.
- `RosterConnection::parameters()`: the one translation of the `connection` config block into predis parameters, shared by `RoomRoster` and the migrate command.

### Changed

- **API change.** Every `RoomRoster` read method takes the application id as an optional final argument: `users($channel, $appId = null)`, `sockets()`, `userCount()`, `socketCount()`, `connectionCount()`, `isOccupied()`, `isOnline($channel, $userId, $appId = null)`, and `occupiedChannels($appId = null)`. Existing single-app calls keep working untouched: with no id given, the sole configured application is used. On a server with several applications an omitted id now throws an `InvalidArgumentException` naming the fix, rather than quietly reading a merged roster. The id is appended rather than prepended on purpose, so no existing call can be silently reinterpreted.
- `RosterKeys` speaks the app-scoped schema: `hashKey($appId, $channel, $node)`, `scanPattern($appId, $channel)`, `appPattern($appId)`, and `channelFromKey($appId, $key)`. The pre-0.3.0 builders live on as `legacyHashKey()`, `legacyScanPattern()`, `legacyAllPattern()`, `isLegacyKeyFor()`, and `legacyChannelFromKey()`, which is what the fallback reads. `allPattern()` is gone; use `appPattern()`. Anything calling `RosterKeys` directly needs updating.
- `RedisRosterPlugin` tracks channels as application id => channel name, so two applications serving the same channel name are two entries instead of one overwriting the other.
- `RoomRoster` takes an optional `ApplicationProvider` as its second constructor argument, which is what resolves the default application. The container binding passes it; code that builds a `RoomRoster` by hand should too, or pass the application id on every call.

### Upgrading

This release changes the key schema, so deploy it in this order. The full procedure, including what an operator sees at each step, is in the README under "Upgrading to the app-scoped key schema".

1. Deploy 0.3.x everywhere with `legacy_fallback` left at `true` (the default).
2. Roll the Resonate nodes one at a time. Upgraded nodes write app-scoped keys, nodes still to come write unscoped ones, and readers merge both per node, so nothing reads as empty mid-deploy.
3. Pass the application id in host code that reads a multi-app roster.
4. Once every node is upgraded, run `php artisan resonate-roster:migrate-keys --dry-run` and then, if anything is left, `resonate-roster:migrate-keys` (or `--prune`).
5. Set `RESONATE_ROSTER_LEGACY_FALLBACK=false` and restart to close the window.

Consumers that read roster keys themselves must be upgraded in step 1 too: use `webpatser/resonate-webhooks` 0.3+ and `webpatser/resonate-pulse` 0.3+, which reads through `RoomRoster::snapshot()` rather than the keyspace and so reports per application.

## [0.2.3] - 2026-07-30

### Fixed

- Allow `webpatser/resonate` v0.5. The constraint was `^0.4`, which under Composer's 0.x caret semantics means `>=0.4 <0.5` and so excluded the current server release, making this package uninstallable alongside it. Now `^0.4|^0.5`. Verified against v0.5.1: the suite is green and the plugin contracts this package implements are unchanged.

## [0.2.2] - 2026-07-30

### Fixed

- `escapeGlob()` declared a `string` return but `preg_replace` can return null; it now uses `addcslashes`, which escapes identically and cannot fail.
- Channel names read back out of connection state could be `int|string`, because PHP coerces numeric-string array keys to integers. They are cast back to strings before use.

### Changed

- CI runs the suite against a real Redis service, so the previously self-skipping integration tests now execute, and adds Pint and PHPStan (level 8, no baseline and no ignores) as gates.

## [0.2.1] - 2026-07-02

### Security
- Escape glob metacharacters in the roster `SCAN MATCH` pattern so channel names cannot widen the scan scope.

## [0.2.0] - 2026-05-22

### Added

- `track` config option (`presence` or `all`). In `all` mode the roster mirrors
  every channel type, not just presence channels, so it doubles as a
  cluster-wide connection count for public and private channels. Default stays
  `presence`, so existing installs are unaffected.
- `RoomRoster::connectionCount()`: connection count for a channel, an alias of
  `socketCount()` that reads naturally for non-presence channels.
- `RoomRoster::isOccupied()`: whether a channel has at least one connection.

## [0.1.0] - 2026-05-22

Initial release.

### Added

- `RedisRosterPlugin`: a Resonate server plugin that mirrors every presence
  channel into Redis. It writes through the fledge-fiber async Redis client, so
  it never blocks the event loop.
  - `onSubscribe` records a connection in this node's roster key.
  - `onUnsubscribe` removes a connection left by an explicit `pusher:unsubscribe`.
  - `onClose` removes a closing connection from every channel it held, reading
    that list from the connection's own state bag (the channel manager has
    already been cleared by the time the close hook fires).
  - A heartbeat tick reconciles each tracked channel against the live
    connections, refreshes the key TTL, and forgets emptied channels. This is
    the authoritative pass that corrects any missed lifecycle hook.
- `RoomRoster`: a synchronous read API for the host Laravel app, backed by
  predis: `users()`, `userCount()`, `sockets()`, `socketCount()`, `isOnline()`,
  and `occupiedChannels()`.
- `RosterKeys`: the key schema shared by the write and read sides, so the two
  can never drift. Membership lives in a per-node Redis hash at
  `{prefix}:{channel}:{node}`, which lets a dead node's entries expire by TTL
  without a live node holding them open.
- `RosterServiceProvider`: registers `RoomRoster` as a singleton and publishes
  the `resonate-roster` config (`vendor:publish --tag=resonate-roster-config`).
- Configurable Redis connection, key prefix, key TTL, and heartbeat interval.

[Unreleased]: https://github.com/webpatser/resonate-roster/compare/v0.2.3...HEAD
[0.2.1]: https://github.com/webpatser/resonate-roster/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/webpatser/resonate-roster/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/resonate-roster/releases/tag/v0.1.0
