# Changelog

All notable changes to `webpatser/resonate-roster` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.1] - 2026-08-02

### Fixed

- Allow `webpatser/resonate` v0.6. The constraint excluded it, so this package could not be installed alongside the current server release. Verified against v0.6.0.

## [0.3.0] - 2026-08-02

### Added

- `legacy_fallback` config flag (`RESONATE_ROSTER_LEGACY_FALLBACK`, default `true`): readers consult a node's pre-0.3.0 unscoped key when it has no app-scoped one, which keeps a rolling deploy correct.
- `php artisan resonate-roster:migrate-keys`: rename leftover pre-0.3.0 keys into the app-scoped schema, preserving TTL. `--app=<id>` attributes them, `--prune` deletes them instead, `--dry-run` reports without writing.
- `RoomRoster::snapshot($appId = null)`: every occupied channel of one application as `channel => ['users' => [...], 'connections' => n]`, in one keyspace sweep plus one pipelined batch of `HGETALL`s.
- Optional ready predis client as `RoomRoster`'s third constructor argument, for hosts and tests that own a connection already.
- `RosterKeys::fromConfig()`, `RosterKeys::DEFAULT_PREFIX`, `prefix()`, and `legacyFallback()`, so consumers build the schema from the roster's own config instead of repeating the prefix literal.
- `RosterConnection::parameters()`: the single translation of the `connection` config block into predis parameters.

### Changed

- Scope every roster key to its application: `{prefix}:{appId}:{channel}:{node}`, replacing `{prefix}:{channel}:{node}`.
- Add the application id as an optional final argument to every `RoomRoster` read method (`users()`, `sockets()`, `userCount()`, `socketCount()`, `connectionCount()`, `isOccupied()`, `isOnline()`, `occupiedChannels()`). Single-app hosts are unaffected; a multi-app host that omits it now throws `InvalidArgumentException` instead of reading a merged roster.
- Move `RosterKeys` to the app-scoped schema: `hashKey($appId, $channel, $node)`, `scanPattern($appId, $channel)`, `appPattern($appId)`, `channelFromKey($appId, $key)`. The pre-0.3.0 builders live on as `legacyHashKey()`, `legacyScanPattern()`, `legacyAllPattern()`, `isLegacyKeyFor()`, and `legacyChannelFromKey()`.
- Track channels in `RedisRosterPlugin` as application id => channel name, so two applications serving one channel name stay separate.
- Add an optional `ApplicationProvider` as `RoomRoster`'s second constructor argument; it resolves the default application. Hand-built instances should pass it or pass the application id on every call.

### Removed

- `RosterKeys::allPattern()`. Use `appPattern($appId)`, or `legacyAllPattern()` while the fallback window is open.

### Fixed

- Stop two applications sharing one Redis hash for a channel of the same name, which reported both memberships as one room and misled every consumer of the keyspace.
- Stop the heartbeat reconcile pass deleting another application's members. Each pass now only touches keys built from the application it reconciles, and never a pre-0.3.0 key.

### Upgrading

The key schema changed from `{prefix}:{channel}:{node}` to `{prefix}:{appId}:{channel}:{node}`. A dual-read window (`legacy_fallback`, default `true`) covers the gap: readers take each node's app-scoped key, and its pre-0.3.0 key when it has none. Deploy in this order.

1. Deploy 0.3.x everywhere with `legacy_fallback` at `true`, consumers included: `webpatser/resonate-webhooks` 0.3+ and `webpatser/resonate-pulse` 0.3+. Older consumers read the wrong keyspace.
2. Roll the Resonate nodes one at a time. Mixed old and new nodes both report correctly, so nothing reads as empty mid-deploy.
3. Update host code that reads a multi-app roster (see the API changes below).
4. Run `php artisan resonate-roster:migrate-keys --dry-run`, then `resonate-roster:migrate-keys` (or `--prune`) if anything is left.
5. Set `RESONATE_ROSTER_LEGACY_FALLBACK=false` and restart to close the window.

API changes to make in step 3:

- `RoomRoster` read methods take an optional trailing `$appId`. A single-app host needs no change; a multi-app host that omits it throws `InvalidArgumentException`.
- `RosterKeys::allPattern()` is gone: use `appPattern($appId)`.
- `RosterKeys::channelFromKey()` takes the application id first: `channelFromKey($appId, $key)`.

The operator walkthrough, with what to expect at each step and how to roll back, is in the README under "Upgrading to the app-scoped key schema".

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

[0.3.0]: https://github.com/webpatser/resonate-roster/compare/v0.2.3...v0.3.0
[0.2.3]: https://github.com/webpatser/resonate-roster/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/webpatser/resonate-roster/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/webpatser/resonate-roster/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/webpatser/resonate-roster/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/resonate-roster/releases/tag/v0.1.0
