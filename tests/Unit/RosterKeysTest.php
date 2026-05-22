<?php

use Webpatser\ResonateRoster\RosterKeys;

it('builds a per-node hash key', function () {
    $keys = new RosterKeys('roster');

    expect($keys->hashKey('presence-chat.42', 'web-1-9001'))
        ->toBe('roster:presence-chat.42:web-1-9001');
});

it('builds a scan pattern that does not match longer channel names', function () {
    $keys = new RosterKeys('roster');

    $pattern = $keys->scanPattern('presence-foo');

    expect($pattern)->toBe('roster:presence-foo:*')
        ->and(fnmatch($pattern, 'roster:presence-foo:web-1-1'))->toBeTrue()
        ->and(fnmatch($pattern, 'roster:presence-foobar:web-1-1'))->toBeFalse();
});

it('extracts the channel name from a full key', function () {
    $keys = new RosterKeys('roster');

    expect($keys->channelFromKey('roster:presence-chat.42:web-1-9001'))
        ->toBe('presence-chat.42');
});

it('extracts the channel name even when the prefix contains a colon', function () {
    $keys = new RosterKeys('app:roster');

    expect($keys->channelFromKey('app:roster:presence-room:web-1-9001'))
        ->toBe('presence-room');
});

it('honours a custom prefix', function () {
    $keys = new RosterKeys('mg');

    expect($keys->hashKey('presence-room', 'n1'))->toBe('mg:presence-room:n1')
        ->and($keys->allPattern())->toBe('mg:*');
});

it('generates a colon-free node id', function () {
    expect(RosterKeys::nodeId())->not->toContain(':')
        ->and(RosterKeys::nodeId())->toContain((string) getmypid());
});
