<?php

declare(strict_types=1);

use Cecureus\Db;
use Cecureus\Security\Otp;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/** Issues a code for a fresh target and returns ['requestId', 'code', 'target']. */
function issueFor(string $purpose, string $target): array
{
    resetOtpLimiter($purpose, $target);
    $otp = Otp::issue($purpose, $target);
    check(isset($otp['requestId'], $otp['code']), 'issue did not return a request');
    check(preg_match('/^\d{6}$/', $otp['code']) === 1, 'code is not 6 digits');
    return $otp;
}

/** Clears the per-target limiter and cooldown so tests can re-issue immediately. */
function resetOtpLimiter(string $purpose, string $target): void
{
    resetLimitBucket("otp:{$purpose}:{$target}");
    Db::run('UPDATE mobile_otp_requests SET created_at = ? WHERE purpose = ? AND target = ?', [Time::utc(time() - 3600), $purpose, $target]);
}

test('a fresh code verifies once and only once', function (): void {
    needDb();
    $target = uniqueEmail('otp-test');
    try {
        $otp = issueFor('register_email', $target);
        $row = Otp::verify($otp['requestId'], $otp['code'], 'register_email');
        check($row['target'] === $target, 'wrong row returned');
        check(Db::value('SELECT consumed_at FROM mobile_otp_requests WHERE request_id = ?', [$otp['requestId']]) !== null, 'code not consumed');
        throwsApi(422, 'OTP_INVALID', fn () => Otp::verify($otp['requestId'], $otp['code'], 'register_email'));
    } finally {
        Db::run('DELETE FROM mobile_otp_requests WHERE target = ?', [$target]);
        resetLimitBucket('otp:register_email:' . $target);
    }
});

test('a wrong code counts attempts and locks after five', function (): void {
    needDb();
    $target = uniqueEmail('otp-test');
    try {
        $otp = issueFor('register_email', $target);
        for ($i = 0; $i < 5; $i++) {
            throwsApi(422, 'OTP_INVALID', fn () => Otp::verify($otp['requestId'], '000000', 'register_email'));
        }
        throwsApi(429, 'OTP_LOCKED', fn () => Otp::verify($otp['requestId'], $otp['code'], 'register_email'));
    } finally {
        Db::run('DELETE FROM mobile_otp_requests WHERE target = ?', [$target]);
        resetLimitBucket('otp:register_email:' . $target);
    }
});

test('expired codes are refused before the attempt counter matters', function (): void {
    needDb();
    $target = uniqueEmail('otp-test');
    try {
        $otp = issueFor('register_email', $target);
        Db::run('UPDATE mobile_otp_requests SET expires_at = ? WHERE request_id = ?', [Time::utc(time() - 10), $otp['requestId']]);
        throwsApi(422, 'OTP_EXPIRED', fn () => Otp::verify($otp['requestId'], $otp['code'], 'register_email'));
    } finally {
        Db::run('DELETE FROM mobile_otp_requests WHERE target = ?', [$target]);
        resetLimitBucket('otp:register_email:' . $target);
    }
});

test('codes are bound to their purpose and their registration', function (): void {
    needDb();
    $target = uniqueEmail('otp-test');
    try {
        $otp = issueFor('register_email', $target);
        $hash = hash('sha256', Text::base64url(random_bytes(32)));
        throwsApi(422, 'OTP_INVALID', fn () => Otp::verify($otp['requestId'], $otp['code'], 'register_phone'));
        throwsApi(422, 'OTP_INVALID', fn () => Otp::verify($otp['requestId'], $otp['code'], 'register_email', $hash));
        throwsApi(422, 'OTP_INVALID', fn () => Otp::verify('not-a-request-id', $otp['code'], 'register_email'));
        throwsApi(422, 'OTP_INVALID', fn () => Otp::verify($otp['requestId'], null, 'register_email'));
    } finally {
        Db::run('DELETE FROM mobile_otp_requests WHERE target = ?', [$target]);
        resetLimitBucket('otp:register_email:' . $target);
    }
});

test('a target cannot get two codes inside the resend cooldown', function (): void {
    needDb();
    $target = uniqueEmail('otp-test');
    try {
        $otp = issueFor('register_email', $target);
        throwsApi(429, 'OTP_COOLDOWN', fn () => Otp::issue('register_email', $target));
        Otp::discard($otp['requestId']);
        check(Db::value('SELECT COUNT(*) FROM mobile_otp_requests WHERE request_id = ?', [$otp['requestId']]) === '0', 'discard left the row');
    } finally {
        Db::run('DELETE FROM mobile_otp_requests WHERE target LIKE ?', [$target . '%']);
        resetLimitBucket('otp:register_email:' . $target);
    }
});

test('issuing replaces any earlier unused code for the same target', function (): void {
    needDb();
    $target = uniqueEmail('otp-test');
    try {
        $first = issueFor('register_email', $target);
        // pretend time moved past the cooldown, then issue again
        Db::run('UPDATE mobile_otp_requests SET created_at = ? WHERE request_id = ?', [Time::utc(time() - 120), $first['requestId']]);
        resetLimitBucket('otp:register_email:' . $target);
        $second = Otp::issue('register_email', $target);
        $leftOpen = Db::value('SELECT COUNT(*) FROM mobile_otp_requests WHERE request_id = ? AND consumed_at IS NULL', [$first['requestId']]);
        check($leftOpen === '0', 'earlier code still open');
        $row = Otp::verify($second['requestId'], $second['code'], 'register_email');
        check($row['target'] === $target, 'second code not verifiable');
    } finally {
        Db::run('DELETE FROM mobile_otp_requests WHERE target = ?', [$target]);
        resetLimitBucket('otp:register_email:' . $target);
    }
});
