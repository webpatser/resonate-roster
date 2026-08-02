<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis connection
    |--------------------------------------------------------------------------
    |
    | The Redis server that holds the roster. Both sides use this single
    | block: the plugin running inside Resonate writes to it over the
    | fledge-fiber async client, and the RoomRoster reader queries it over
    | predis from your Laravel app. They must point at the same server and
    | database, so keep this as one source of truth.
    |
    */

    'connection' => [
        'url' => env('RESONATE_ROSTER_REDIS_URL', env('REDIS_URL')),
        'host' => env('RESONATE_ROSTER_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
        'port' => env('RESONATE_ROSTER_REDIS_PORT', env('REDIS_PORT', '6379')),
        'username' => env('RESONATE_ROSTER_REDIS_USERNAME', env('REDIS_USERNAME')),
        'password' => env('RESONATE_ROSTER_REDIS_PASSWORD', env('REDIS_PASSWORD')),
        'database' => env('RESONATE_ROSTER_REDIS_DB', env('REDIS_DB', '0')),
        'timeout' => env('RESONATE_ROSTER_REDIS_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key prefix
    |--------------------------------------------------------------------------
    |
    | Every roster key is namespaced under this prefix. A presence channel C
    | of application A on node N is stored at "{prefix}:{A}:{C}:{N}". Avoid
    | colons in the prefix.
    |
    */

    'key_prefix' => env('RESONATE_ROSTER_PREFIX', 'roster'),

    /*
    |--------------------------------------------------------------------------
    | Legacy key fallback (the upgrade window)
    |--------------------------------------------------------------------------
    |
    | Before 0.3.0 a roster key was "{prefix}:{C}:{N}", with no application
    | segment. While this is true, readers that find no app-scoped key for a
    | node fall back to that node's pre-0.3.0 key, so a rolling deploy of
    | mixed old and new nodes keeps reporting correct membership.
    |
    | Set it to false once every node runs 0.3.0 or later and no pre-0.3.0
    | keys remain (`php artisan resonate-roster:migrate-keys` reports that,
    | and can rename or prune whatever is left). Turning it off is what makes
    | occupancy strictly per application. See the README upgrade section.
    |
    */

    'legacy_fallback' => (bool) env('RESONATE_ROSTER_LEGACY_FALLBACK', true),

    /*
    |--------------------------------------------------------------------------
    | Key TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Each node's roster key carries this TTL, refreshed on every heartbeat.
    | If a node dies without cleaning up, its key expires on its own after
    | this window. Keep it comfortably larger than the heartbeat interval.
    |
    */

    'ttl' => (int) env('RESONATE_ROSTER_TTL', 90),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat interval (seconds)
    |--------------------------------------------------------------------------
    |
    | How often the plugin reconciles each tracked presence channel against
    | the live connections and refreshes the TTL. This tick is authoritative:
    | it corrects any drift left by a missed lifecycle hook.
    |
    */

    'heartbeat_interval' => (int) env('RESONATE_ROSTER_HEARTBEAT', 30),

    /*
    |--------------------------------------------------------------------------
    | Channels to track
    |--------------------------------------------------------------------------
    |
    | 'presence' mirrors only presence-* channels, the classic "who is online"
    | roster. 'all' mirrors every channel type, so the roster also answers
    | "how many connections does this public or private channel have" - which
    | is what an occupancy consumer such as webpatser/resonate-webhooks needs.
    |
    | Supported: 'presence', 'all'
    |
    */

    'track' => env('RESONATE_ROSTER_TRACK', 'presence'),

];
