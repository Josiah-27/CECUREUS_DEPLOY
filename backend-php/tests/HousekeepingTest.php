<?php

declare(strict_types=1);

use Cecureus\Db;
use Cecureus\Repo\Housekeeping;
use Cecureus\Support\Time;

test('housekeeping deletes expired and revoked sessions, keeps fresh ones', function (): void {
    needDb();
    $fresh = 'hk-fresh-' . bin2hex(random_bytes(4));
    $expired = 'hk-expired-' . bin2hex(random_bytes(4));
    $revoked = 'hk-revoked-' . bin2hex(random_bytes(4));
    $sessions = [
        [$fresh, Time::utc(time() + 3600), null],
        [$expired, Time::utc(time() - 60), null],
        [$revoked, Time::utc(time() + 3600), Time::utc(time() - 30)],
    ];
    foreach ($sessions as [$hash, $expires, $revokedAt]) {
        Db::insert('mobile_sessions', [
            'token_hash' => hash('sha256', $hash),
            'emp_id' => 999999,
            'created_at' => Time::utc(time()),
            'expires_at' => $expires,
            'last_used_at' => Time::utc(time()),
            'revoked_at' => $revokedAt,
            'user_agent' => 'housekeeping-test',
        ]);
    }
    try {
        Housekeeping::cleanTables();
        $still = static fn (string $hash): ?string => Db::value('SELECT token_hash FROM mobile_sessions WHERE token_hash = ?', [hash('sha256', $hash)]);
        check($still($fresh) !== null, 'fresh session deleted');
        check($still($expired) === null, 'expired session kept');
        check($still($revoked) === null, 'revoked session kept');
    } finally {
        foreach ([$fresh, $expired, $revoked] as $hash) {
            Db::run('DELETE FROM mobile_sessions WHERE token_hash = ?', [hash('sha256', $hash)]);
        }
    }
});

test('housekeeping keeps OTP rows one extra day and clears older ones', function (): void {
    needDb();
    $target = uniqueEmail('hk-otp');
    $rows = [
        ['hk-otp-open', Time::utc(time() + 600)],
        ['hk-otp-recent-dead', Time::utc(time() - 3600)],
        ['hk-otp-old-dead', Time::utc(time() - 86400 * 2)],
    ];
    foreach ($rows as [$id, $expires]) {
        Db::insert('mobile_otp_requests', [
            'request_id' => md5($id),
            'purpose' => 'register_email',
            'target' => $target,
            'meta' => '{}',
            'code_hash' => str_repeat('0', 64),
            'attempts' => 0,
            'created_at' => Time::utc(time()),
            'expires_at' => $expires,
        ]);
    }
    try {
        Housekeeping::cleanTables();
        $still = static fn (string $id): ?string => Db::value('SELECT request_id FROM mobile_otp_requests WHERE request_id = ?', [md5($id)]);
        check($still('hk-otp-open') !== null, 'open code deleted');
        check($still('hk-otp-recent-dead') !== null, 'day-old expired code deleted too early');
        check($still('hk-otp-old-dead') === null, 'two-day-old expired code kept');
    } finally {
        Db::run('DELETE FROM mobile_otp_requests WHERE target = ?', [$target]);
    }
});

test('housekeeping clears expired sign-ups and stale rate-limit windows', function (): void {
    needDb();
    $email = uniqueEmail('hk-reg');
    $bucket = hash('sha256', 'hk-limit-' . bin2hex(random_bytes(4)));
    Db::insert('mobile_registrations', [
        'token_hash' => hash('sha256', 'hk-reg-' . bin2hex(random_bytes(8))),
        'email' => $email,
        'user_type' => 'individual',
        'created_at' => Time::utc(time() - 7200),
        'expires_at' => Time::utc(time() - 3600),
    ]);
    Db::insert('mobile_rate_limits', ['bucket' => $bucket, 'window_start' => Time::utc(time() - 86400 * 2), 'hits' => 3]);
    try {
        Housekeeping::cleanTables();
        check(Db::value('SELECT email FROM mobile_registrations WHERE email = ?', [$email]) === null, 'expired sign-up kept');
        check(Db::value('SELECT hits FROM mobile_rate_limits WHERE bucket = ?', [$bucket]) === null, 'stale rate-limit window kept');
    } finally {
        Db::run('DELETE FROM mobile_registrations WHERE email = ?', [$email]);
        Db::run('DELETE FROM mobile_rate_limits WHERE bucket = ?', [$bucket]);
    }
});
