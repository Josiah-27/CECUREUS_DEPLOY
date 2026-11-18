<?php

declare(strict_types=1);

namespace Cecureus\Security;

use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Repo\Employees;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * Opaque bearer tokens (32 random bytes, base64url). Only the SHA-256 of a token is stored, so a
 * leaked database cannot be used to sign in. Tokens last 30 days and are revoked on logout,
 * account deletion, or when the account stops being Active.
 */
final class Sessions
{
    public const TTL_SECONDS = 30 * 24 * 3600;

    /** @return array{accessToken: string, expiresAt: string, user: array<string, mixed>} */
    public static function create(array $employee, Request $request): array
    {
        $token = Text::base64url(random_bytes(32));
        $now = time();
        $expires = $now + self::TTL_SECONDS;
        Db::insert('mobile_sessions', [
            'token_hash' => hash('sha256', $token),
            'emp_id' => (int) $employee['emp_id'],
            'created_at' => Time::utc($now),
            'expires_at' => Time::utc($expires),
            'last_used_at' => Time::utc($now),
            'user_agent' => $request->userAgent(),
        ]);
        return ['accessToken' => $token, 'expiresAt' => Time::isoUtc($expires), 'user' => Employees::toUser($employee)];
    }

    /**
     * The signed-in employee_list row, or 401. Call this first in every endpoint that needs login.
     * @return array<string, string|null>
     */
    public static function authenticate(Request $request): array
    {
        $unauthenticated = new ApiException(401, 'UNAUTHENTICATED', 'Your session has expired. Please sign in again.');
        $token = $request->bearerToken();
        if ($token === null) {
            throw $unauthenticated;
        }
        $session = Db::one(
            'SELECT token_hash, emp_id, expires_at, last_used_at FROM mobile_sessions WHERE token_hash = ? AND revoked_at IS NULL',
            [hash('sha256', $token)],
        );
        if ($session === null || Time::fromUtc($session['expires_at']) <= time()) {
            throw $unauthenticated;
        }
        $employee = Employees::findActiveById((int) $session['emp_id']);
        if ($employee === null) {
            // Account was deactivated/cancelled by the team: end every session for it.
            self::revokeAll((int) $session['emp_id']);
            throw $unauthenticated;
        }
        if (time() - Time::fromUtc($session['last_used_at']) > 300) {
            Db::run('UPDATE mobile_sessions SET last_used_at = ? WHERE token_hash = ?', [Time::utc(time()), $session['token_hash']]);
        }
        return $employee;
    }

    public static function revoke(?string $token): void
    {
        if ($token !== null) {
            Db::run('UPDATE mobile_sessions SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL', [
                Time::utc(time()),
                hash('sha256', $token),
            ]);
        }
    }

    public static function revokeAll(int $empId): void
    {
        Db::run('UPDATE mobile_sessions SET revoked_at = ? WHERE emp_id = ? AND revoked_at IS NULL', [Time::utc(time()), $empId]);
    }
}
