<?php

declare(strict_types=1);

namespace Cecureus\Security;

use Cecureus\Config;

/**
 * New passwords: Argon2id (or bcrypt where Argon2 is not compiled in). emp_password is varchar(500).
 * Existing rows: if LEGACY_PASSWORD_FORMATS includes "md5", an unsalted MD5 hash is accepted once and
 * immediately re-hashed with Argon2id/bcrypt on successful login (transparent migration).
 * Never enable "plain" unless you have confirmed the old site stored plain text — and then migrate.
 */
final class Passwords
{
    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function verify(string $stored, string $given): bool
    {
        $stored = trim($stored);
        if ($stored === '' || $given === '') {
            return false;
        }
        // password_get_info() reports algo null (PHP ≥ 7.4) or 0 (older) for non-password_hash() values.
        if (!empty(password_get_info($stored)['algo'])) {
            return password_verify($given, $stored);
        }
        $legacy = Config::list('LEGACY_PASSWORD_FORMATS');
        if (in_array('md5', $legacy, true) && preg_match('/^[a-f0-9]{32}$/i', $stored)) {
            return hash_equals(strtolower($stored), md5($given));
        }
        if (in_array('plain', $legacy, true)) {
            return hash_equals($stored, $given);
        }
        return false;
    }

    public static function needsRehash(string $stored): bool
    {
        $stored = trim($stored);
        return empty(password_get_info($stored)['algo']) || password_needs_rehash($stored, self::algo());
    }

    /** Spends the same time as a real check when the user ID does not exist (no account enumeration by timing). */
    public static function burn(string $given): void
    {
        static $dummy = null;
        $dummy ??= password_hash('cecureus-dummy-password', self::algo());
        password_verify($given, $dummy);
    }

    /** Min 8 characters with a letter and a number (contract rule). */
    public static function strongEnough(string $password): bool
    {
        return strlen($password) >= 8
            && strlen($password) <= 128
            && preg_match('/\pL/u', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }

    private static function algo(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
