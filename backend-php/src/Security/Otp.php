<?php

declare(strict_types=1);

namespace Cecureus\Security;

use Cecureus\Config;
use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Support\Time;

/**
 * One-time codes for sign-up (email step and mobile step).
 *
 * - 6 random digits from random_int (CSPRNG). Only an HMAC of the code is stored, never the code.
 * - Valid 10 minutes, 60 s resend cooldown, 5 wrong tries per code, 5 codes per target per hour.
 * - The code is never returned by the API; it goes only to the email inbox / phone.
 * Rows live in mobile_otp_requests (employee_list.emp_otp is not used).
 */
final class Otp
{
    public const TTL_SECONDS = 600;
    public const RESEND_SECONDS = 60;
    public const MAX_ATTEMPTS = 5;
    public const MAX_PER_HOUR = 5;

    /**
     * @param array<string, string> $meta small context saved with the request (e.g. userType)
     * @return array{requestId: string, code: string}
     */
    public static function issue(string $purpose, string $target, ?string $registrationId = null, array $meta = []): array
    {
        $now = time();
        $last = Db::value(
            'SELECT created_at FROM mobile_otp_requests WHERE purpose = ? AND target = ? ORDER BY created_at DESC LIMIT 1',
            [$purpose, $target],
        );
        if ($last !== null) {
            $age = $now - Time::fromUtc($last);
            if ($age < self::RESEND_SECONDS) {
                throw new ApiException(429, 'OTP_COOLDOWN', 'Please wait before requesting another code.', self::RESEND_SECONDS - $age);
            }
        }
        RateLimiter::consume(
            "otp:{$purpose}:{$target}",
            self::MAX_PER_HOUR,
            3600,
            'OTP_LIMIT',
            'Too many codes requested for this address. Please try again later.',
        );

        // A new code replaces any earlier unused one for the same target.
        Db::run(
            'UPDATE mobile_otp_requests SET consumed_at = ? WHERE purpose = ? AND target = ? AND consumed_at IS NULL',
            [Time::utc($now), $purpose, $target],
        );

        $requestId = bin2hex(random_bytes(16));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Db::insert('mobile_otp_requests', [
            'request_id' => $requestId,
            'purpose' => $purpose,
            'target' => $target,
            'registration_id' => $registrationId,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'code_hash' => self::hash($requestId, $code),
            'attempts' => 0,
            'created_at' => Time::utc($now),
            'expires_at' => Time::utc($now + self::TTL_SECONDS),
        ]);
        return ['requestId' => $requestId, 'code' => $code];
    }

    /**
     * Checks a code. On success the request is consumed (single use) and its row is returned.
     * @return array<string, string|null>
     */
    public static function verify(mixed $requestId, mixed $code, string $purpose, ?string $registrationId = null): array
    {
        $invalid = new ApiException(422, 'OTP_INVALID', 'This code is not valid.');
        if (!is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            throw $invalid;
        }
        $row = Db::one('SELECT * FROM mobile_otp_requests WHERE request_id = ?', [$requestId]);
        if (
            $row === null
            || $row['purpose'] !== $purpose
            || $row['consumed_at'] !== null
            || ($registrationId !== null && !hash_equals((string) $row['registration_id'], $registrationId))
        ) {
            throw $invalid;
        }
        if (Time::fromUtc($row['expires_at']) <= time()) {
            throw new ApiException(422, 'OTP_EXPIRED', 'This code has expired. Request a new one.');
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            throw new ApiException(429, 'OTP_LOCKED', 'Too many incorrect attempts. Request a new code.');
        }
        $code = is_string($code) || is_int($code) ? trim((string) $code) : '';
        if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string) $row['code_hash'], self::hash($requestId, $code))) {
            Db::run('UPDATE mobile_otp_requests SET attempts = attempts + 1 WHERE request_id = ?', [$requestId]);
            throw new ApiException(422, 'OTP_INVALID', 'Incorrect code. Please try again.');
        }
        // Single use, even if two verify calls race.
        $consumed = Db::run(
            'UPDATE mobile_otp_requests SET consumed_at = ? WHERE request_id = ? AND consumed_at IS NULL',
            [Time::utc(time()), $requestId],
        );
        if ($consumed !== 1) {
            throw $invalid;
        }
        return $row;
    }

    /** Lets the person retry at once when the code could not be delivered. */
    public static function discard(string $requestId): void
    {
        Db::run('DELETE FROM mobile_otp_requests WHERE request_id = ?', [$requestId]);
    }

    private static function hash(string $requestId, string $code): string
    {
        return hash_hmac('sha256', $requestId . ':' . $code, Config::secret());
    }
}
