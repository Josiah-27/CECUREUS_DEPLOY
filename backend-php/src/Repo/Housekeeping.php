<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Db;

/**
 * Daily cleanup of the mobile_* helper tables (bin/housekeeping.php). Nothing here is needed for
 * correct responses: authenticate() ignores expired and revoked sessions, Registrations::find()
 * and Otp::verify() ignore expired rows, and a stale rate-limit window is reset on the next hit.
 * This only stops the tables from growing forever.
 */
final class Housekeeping
{
    /** Deletes dead rows; returns the number of rows deleted per table. @return array<string, int> */
    public static function cleanTables(): array
    {
        return [
            // authenticate() needs neither expired nor revoked rows.
            'mobile_sessions' => Db::run(
                'DELETE FROM mobile_sessions WHERE expires_at < UTC_TIMESTAMP() OR revoked_at IS NOT NULL',
            ),
            // Keep consumed/expired codes a day so a "code did not arrive" support question can be answered.
            'mobile_otp_requests' => Db::run(
                'DELETE FROM mobile_otp_requests WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY',
            ),
            'mobile_registrations' => Db::run('DELETE FROM mobile_registrations WHERE expires_at < UTC_TIMESTAMP()'),
            // Fixed windows are at most an hour long; older ones are never incremented again.
            'mobile_rate_limits' => Db::run('DELETE FROM mobile_rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY'),
        ];
    }
}
