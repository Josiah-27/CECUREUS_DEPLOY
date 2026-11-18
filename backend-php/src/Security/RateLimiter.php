<?php

declare(strict_types=1);

namespace Cecureus\Security;

use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Support\Time;

/**
 * Fixed-window counters in the mobile_rate_limits table. Bucket names are hashed, so no email or
 * phone number is stored in clear. These limits are what protects the SMS credits and the
 * password check from brute force — they must be enforced here, on the server.
 */
final class RateLimiter
{
    /** Counts one hit and throws 429 when more than $max hits happened in the window. */
    public static function consume(
        string $name,
        int $max,
        int $windowSeconds,
        string $code = 'RATE_LIMITED',
        string $message = 'Too many attempts. Please wait a moment and try again.',
    ): void {
        $state = self::hit($name, $windowSeconds);
        if ($state['hits'] > $max) {
            throw new ApiException(429, $code, $message, $state['retryAfter']);
        }
    }

    /** Throws 429 if the bucket already has $max hits in the window (does not count this call). */
    public static function check(string $name, int $max, int $windowSeconds, string $code, string $message): void
    {
        $row = Db::one('SELECT window_start, hits FROM mobile_rate_limits WHERE bucket = ?', [self::bucket($name)]);
        if ($row === null) {
            return;
        }
        $start = Time::fromUtc($row['window_start']);
        if ($start + $windowSeconds > time() && (int) $row['hits'] >= $max) {
            throw new ApiException(429, $code, $message, $start + $windowSeconds - time());
        }
    }

    /** @return array{hits: int, retryAfter: int} */
    public static function hit(string $name, int $windowSeconds): array
    {
        $now = time();
        $nowStr = Time::utc($now);
        $cutoff = Time::utc($now - $windowSeconds);
        $bucket = self::bucket($name);
        // Atomic upsert: a window older than the cutoff restarts at 1. (MySQL evaluates the SET list
        // left to right, so `hits` is computed from the old window_start.)
        Db::run(
            'INSERT INTO mobile_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start <= ?, 1, hits + 1),
               window_start = IF(window_start <= ?, ?, window_start)',
            [$bucket, $nowStr, $cutoff, $cutoff, $nowStr],
        );
        $row = Db::one('SELECT window_start, hits FROM mobile_rate_limits WHERE bucket = ?', [$bucket]);
        $start = Time::fromUtc($row['window_start'] ?? $nowStr);
        return ['hits' => (int) ($row['hits'] ?? 1), 'retryAfter' => max(1, $start + $windowSeconds - $now)];
    }

    public static function reset(string $name): void
    {
        Db::run('DELETE FROM mobile_rate_limits WHERE bucket = ?', [self::bucket($name)]);
    }

    private static function bucket(string $name): string
    {
        return hash('sha256', $name);
    }
}
