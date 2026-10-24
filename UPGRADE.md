# Upgrade Guide

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
