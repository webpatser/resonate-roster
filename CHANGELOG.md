# Changelog

All notable changes to `webpatser/resonate-roster` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/webpatser/resonate-roster/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/webpatser/resonate-roster/releases/tag/v0.1.0
