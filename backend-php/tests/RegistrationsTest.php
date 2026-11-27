<?php

declare(strict_types=1);

use Cecureus\Db;
use Cecureus\Repo\Registrations;
use Cecureus\Support\Time;

/** @return array{0: string, 1: string} [email, token] of a fresh sign-up in progress */
function seedRegistration(): array
{
    $email = uniqueEmail('reg');
    $token = Registrations::create($email, 'individual', '');
    return [$email, $token];
}

function deleteRegistration(string $email): void
{
    Db::run('DELETE FROM mobile_registrations WHERE email = ?', [$email]);
}

test('create stores only the hash and find returns the row', function (): void {
    needDb();
    [$email, $token] = seedRegistration();
    try {
        $row = Registrations::find($token);
        check($row['email'] === $email, 'email not stored');
        check($row['user_type'] === 'individual', 'user type not stored');
        check($row['token_hash'] === hash('sha256', $token), 'token stored in clear');
        check($row['mobile'] === null && $row['phone_verified_at'] === null, 'fresh sign-up not empty');
    } finally {
        deleteRegistration($email);
    }
});

test('find rejects broken tokens and expired sign-ups the same way', function (): void {
    needDb();
    foreach (['', 'short', 'not-a-token-!!!', str_repeat('x', 200)] as $bad) {
        throwsApi(422, 'REGISTRATION_EXPIRED', fn () => Registrations::find($bad));
    }
    [$email, $token] = seedRegistration();
    try {
        Db::run('UPDATE mobile_registrations SET expires_at = ? WHERE token_hash = ?', [Time::utc(time() - 60), hash('sha256', $token)]);
        throwsApi(422, 'REGISTRATION_EXPIRED', fn () => Registrations::find($token));
    } finally {
        deleteRegistration($email);
    }
});

test('setMobile saves the number and clears an earlier verification', function (): void {
    needDb();
    [$email, $token] = seedRegistration();
    try {
        $hash = hash('sha256', $token);
        Registrations::setMobile($hash, '+919876543210');
        check(Registrations::find($token)['mobile'] === '+919876543210', 'mobile not saved');
        Registrations::markPhoneVerified($hash);
        check(Registrations::find($token)['phone_verified_at'] !== null, 'verification not marked');
        Registrations::setMobile($hash, '+919876543999');
        $row = Registrations::find($token);
        check($row['mobile'] === '+919876543999', 'new mobile not saved');
        check($row['phone_verified_at'] === null, 'old verification survived a mobile change');
    } finally {
        deleteRegistration($email);
    }
});

test('a second sign-up for the same email replaces the first', function (): void {
    needDb();
    [$email, $first] = seedRegistration();
    try {
        $second = Registrations::create($email, 'individual', '');
        check(Registrations::find($second)['email'] === $email, 'new token not usable');
        throwsApi(422, 'REGISTRATION_EXPIRED', fn () => Registrations::find($first));
        $rows = (int) Db::value('SELECT COUNT(*) FROM mobile_registrations WHERE email = ?', [$email]);
        check($rows === 1, 'old sign-up row left behind');
    } finally {
        deleteRegistration($email);
    }
});

test('delete removes the sign-up', function (): void {
    needDb();
    [$email, $token] = seedRegistration();
    try {
        Registrations::delete(hash('sha256', $token));
        throwsApi(422, 'REGISTRATION_EXPIRED', fn () => Registrations::find($token));
        $rows = (int) Db::value('SELECT COUNT(*) FROM mobile_registrations WHERE email = ?', [$email]);
        check($rows === 0, 'row not deleted');
    } finally {
        deleteRegistration($email);
    }
});
