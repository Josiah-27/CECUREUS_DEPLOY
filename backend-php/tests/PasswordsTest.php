<?php

declare(strict_types=1);

use Cecureus\Security\Passwords;

test('strongEnough applies the contract rule', function (): void {
    check(Passwords::strongEnough('Password1'), 'good password rejected');
    check(Passwords::strongEnough('longenough1'), 'letters plus digit rejected');
    check(!Passwords::strongEnough('short1'), 'too short accepted');
    check(!Passwords::strongEnough('nodigitsatall'), 'no digit accepted');
    check(!Passwords::strongEnough('12345678'), 'no letter accepted');
    check(!Passwords::strongEnough(str_repeat('a', 129) . '1'), 'too long accepted');
    check(!Passwords::strongEnough(''), 'empty accepted');
});

test('hash and verify roundtrip', function (): void {
    $hash = Passwords::hash('Password1');
    check(Passwords::verify($hash, 'Password1'), 'correct password rejected');
    check(!Passwords::verify($hash, 'WrongPass1'), 'wrong password accepted');
    check(!Passwords::verify('', 'Password1'), 'empty stored hash accepted');
    check(!Passwords::verify($hash, ''), 'empty given password accepted');
    check(Passwords::needsRehash($hash) === false, 'fresh hash needs rehash');
});

test('legacy md5 hashes verify once LEGACY_PASSWORD_FORMATS says so', function (): void {
    $md5 = md5('Password1');
    putenv('LEGACY_PASSWORD_FORMATS=md5');
    try {
        check(Passwords::verify($md5, 'Password1'), 'md5 hash rejected');
        check(!Passwords::verify($md5, 'WrongPass1'), 'md5 accepted the wrong password');
        check(Passwords::needsRehash($md5), 'md5 hash not marked for upgrade');
    } finally {
        putenv('LEGACY_PASSWORD_FORMATS');
    }
});

test('unknown hash formats never verify', function (): void {
    putenv('LEGACY_PASSWORD_FORMATS=md5');
    try {
        check(!Passwords::verify('plaintext-password', 'plaintext-password'), 'plain accepted while only md5 is enabled');
        check(!Passwords::verify('not-a-hash-at-all', 'x'), 'unknown format accepted');
    } finally {
        putenv('LEGACY_PASSWORD_FORMATS');
    }
});

test('burn spends real time instead of returning instantly', function (): void {
    $start = hrtime(true);
    Passwords::burn('whatever-password1');
    $ms = (hrtime(true) - $start) / 1e6;
    check($ms >= 1.0, "burn finished in {$ms}ms, a real check would take longer");
});
