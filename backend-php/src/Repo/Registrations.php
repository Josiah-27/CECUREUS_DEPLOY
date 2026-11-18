<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * A sign-up in progress (after the email code is verified, before the account exists).
 * The app holds an opaque registrationToken; only its SHA-256 is stored, in mobile_registrations.
 * Nothing is written to employee_list until /auth/register/complete succeeds.
 */
final class Registrations
{
    public const TTL_SECONDS = 1800;

    public static function create(string $email, string $userType, string $companyName): string
    {
        $token = Text::base64url(random_bytes(32));
        $now = time();
        // One open sign-up per email.
        Db::run('DELETE FROM mobile_registrations WHERE email = ?', [$email]);
        Db::insert('mobile_registrations', [
            'token_hash' => hash('sha256', $token),
            'email' => $email,
            'user_type' => $userType,
            'company_name' => $companyName,
            'mobile' => null,
            'phone_verified_at' => null,
            'created_at' => Time::utc($now),
            'expires_at' => Time::utc($now + self::TTL_SECONDS),
        ]);
        return $token;
    }

    /** @return array<string, string|null> the row (token_hash is the id to pass around) */
    public static function find(mixed $token): array
    {
        $expired = new ApiException(422, 'REGISTRATION_EXPIRED', 'Your sign-up session has expired. Please start again.');
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $token)) {
            throw $expired;
        }
        $row = Db::one('SELECT * FROM mobile_registrations WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($row === null || Time::fromUtc($row['expires_at']) <= time()) {
            throw $expired;
        }
        return $row;
    }

    public static function setMobile(string $tokenHash, string $mobileE164): void
    {
        Db::run('UPDATE mobile_registrations SET mobile = ?, phone_verified_at = NULL WHERE token_hash = ?', [$mobileE164, $tokenHash]);
    }

    public static function markPhoneVerified(string $tokenHash): void
    {
        Db::run('UPDATE mobile_registrations SET phone_verified_at = ? WHERE token_hash = ?', [Time::utc(time()), $tokenHash]);
    }

    public static function delete(string $tokenHash): void
    {
        Db::run('DELETE FROM mobile_registrations WHERE token_hash = ?', [$tokenHash]);
    }
}
