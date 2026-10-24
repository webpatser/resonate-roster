<?php

use Webpatser\ResonateRoster\RosterKeys;

it('builds a per-application, per-node hash key', function () {
    $keys = new RosterKeys('roster');

    expect($keys->hashKey('app-1', 'presence-chat.42', 'web-1-9001'))
        ->toBe('roster:app-1:presence-chat.42:web-1-9001');
});

it('gives two applications sharing a channel name separate keys', function () {
    $keys = new RosterKeys('roster');

    expect($keys->hashKey('app-1', 'presence-lobby', 'web-1-9001'))
        ->not->toBe($keys->hashKey('app-2', 'presence-lobby', 'web-1-9001'));
});

it('builds a scan pattern that does not match longer channel names', function () {
    $keys = new RosterKeys('roster');

    $pattern = $keys->scanPattern('app-1', 'presence-foo');

    expect($pattern)->toBe('roster:app-1:presence-foo:*')
        ->and(fnmatch($pattern, 'roster:app-1:presence-foo:web-1-1'))->toBeTrue()
        ->and(fnmatch($pattern, 'roster:app-1:presence-foobar:web-1-1'))->toBeFalse()
        ->and(fnmatch($pattern, 'roster:app-2:presence-foo:web-1-1'))->toBeFalse();
});

it('escapes glob metacharacters in the application and channel', function () {
    $keys = new RosterKeys('roster');

    $pattern = $keys->scanPattern('app*1', 'presence-a*[x]');

    // The "*" and "[" are backslash-escaped, so the pattern matches only the
    // literal names, not arbitrary keys it would otherwise glob over.
    expect($pattern)->toBe('roster:app\\*1:presence-a\\*\\[x]:*')
        ->and(fnmatch($pattern, 'roster:app*1:presence-a*[x]:web-1-1'))->toBeTrue()
        ->and(fnmatch($pattern, 'roster:appZZ1:presence-aZZZ[x]:web-1-1'))->toBeFalse();
});

it('extracts the channel name from a full key', function () {
    $keys = new RosterKeys('roster');

    expect($keys->channelFromKey('app-1', 'roster:app-1:presence-chat.42:web-1-9001'))
        ->toBe('presence-chat.42');
});

it('extracts the channel name even when the prefix contains a colon', function () {
    $keys = new RosterKeys('app:roster');

    expect($keys->channelFromKey('app-1', 'app:roster:app-1:presence-room:web-1-9001'))
        ->toBe('presence-room');
});

it('does not extract a channel from another application or an unscoped key', function () {
    $keys = new RosterKeys('roster');

    expect($keys->channelFromKey('app-1', 'roster:app-2:presence-room:web-1-9001'))->toBeNull()
        ->and($keys->channelFromKey('app-1', 'roster:app-1:web-1-9001'))->toBeNull();
});

it('extracts the node id from a key', function () {
    $keys = new RosterKeys('roster');

    expect($keys->nodeFromKey('roster:app-1:presence-room:web-1-9001'))->toBe('web-1-9001');
});

it('builds the app pattern', function () {
    $keys = new RosterKeys('roster');

    expect($keys->appPattern('app-1'))->toBe('roster:app-1:*');
});

it('honours a custom prefix', function () {
    $keys = new RosterKeys('mg');

    expect($keys->hashKey('app-1', 'presence-room', 'n1'))->toBe('mg:app-1:presence-room:n1')
        ->and($keys->appPattern('app-1'))->toBe('mg:app-1:*');
});

it('builds itself from the roster config', function () {
    $keys = RosterKeys::fromConfig(['key_prefix' => 'mg']);

    expect($keys->prefix())->toBe('mg');
});

it('defaults to the published prefix', function () {
    $keys = RosterKeys::fromConfig([]);

    expect($keys->prefix())->toBe(RosterKeys::DEFAULT_PREFIX)
        ->and(RosterKeys::DEFAULT_PREFIX)->toBe('roster');
});

it('generates a colon-free node id', function () {
    expect(RosterKeys::nodeId())->not->toContain(':')
        ->and(RosterKeys::nodeId())->toContain((string) getmypid());
});
