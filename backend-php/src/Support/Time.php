<?php

declare(strict_types=1);

namespace Cecureus\Support;

/**
 * Date/time helpers. The legacy tables store India Standard Time (UTC+05:30, no DST):
 *   booknewdate "2026-02-07", meating_timelist "2:00 PM - 3:00 PM", created_at "07-02-2026 12:09:31 PM".
 * The API sends ISO-8601 with an offset, e.g. "2026-11-02T10:00:00+05:30".
 */
final class Time
{
    public const IST_OFFSET = 19800; // 5h30m in seconds
    public const TIMEZONE = 'Asia/Kolkata';

    /** UTC "Y-m-d H:i:s" for the mobile_* helper tables. */
    public static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function fromUtc(?string $value): int
    {
        return $value === null || $value === '' ? 0 : (int) strtotime($value . ' UTC');
    }

    /** ISO-8601 UTC, e.g. for AuthSession.expiresAt. */
    public static function isoUtc(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** IST calendar date + minutes after IST midnight → ISO with +05:30. */
    public static function istIso(string $ymd, int $minutes): string
    {
        return gmdate('Y-m-d\TH:i:s', self::istToTs($ymd, $minutes) + self::IST_OFFSET) . '+05:30';
    }

    public static function istToTs(string $ymd, int $minutes): int
    {
        return (int) strtotime($ymd . ' 00:00:00 UTC') + $minutes * 60 - self::IST_OFFSET;
    }

    /** @return array{ymd: string, minutes: int, weekday: string} weekday = "monday" … "sunday" */
    public static function istParts(int $ts): array
    {
        $t = $ts + self::IST_OFFSET;
        return [
            'ymd' => gmdate('Y-m-d', $t),
            'minutes' => (int) gmdate('G', $t) * 60 + (int) gmdate('i', $t),
            'weekday' => strtolower(gmdate('l', $t)),
        ];
    }

    /** Parses an ISO-8601 instant with Z or an offset; null when it is not one. */
    public static function parseIso(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    public static function isYmd(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function addDays(string $ymd, int $days): string
    {
        return gmdate('Y-m-d', (int) strtotime($ymd . ' 00:00:00 UTC') + $days * 86400);
    }

    public static function daysBetween(string $from, string $to): int
    {
        return (int) round(((int) strtotime($to . ' UTC') - (int) strtotime($from . ' UTC')) / 86400);
    }

    /** Legacy table timestamp in IST: "07-02-2026 12:09:31 PM". */
    public static function legacyTimestamp(int $ts): string
    {
        return gmdate('d-m-Y h:i:s A', $ts + self::IST_OFFSET);
    }

    /** "07-02-2026 12:09:31 PM" (IST) → ISO, or null. */
    public static function legacyToIso(?string $value): ?string
    {
        $value = trim((string) $value);
        $d = \DateTimeImmutable::createFromFormat('d-m-Y h:i:s A', $value, new \DateTimeZone(self::TIMEZONE));
        return $d === false ? null : $d->format(DATE_ATOM);
    }

    /** "6:00 PM" / "06:00PM" / "18:00" → minutes after midnight (1080); null if unparsable. */
    public static function parseClock(?string $value): ?int
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([AaPp])\.?\s*[Mm]\.?$/', $value, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h < 1 || $h > 12 || $min > 59) {
                return null;
            }
            return (($h % 12) + (strtoupper($m[3]) === 'P' ? 12 : 0)) * 60 + $min;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            return $h > 23 || $min > 59 ? null : $h * 60 + $min;
        }
        return null;
    }

    /** 1080 → "6:00 PM" (the format the website writes). */
    public static function formatClock(int $minutes): string
    {
        $h24 = intdiv($minutes, 60) % 24;
        $h12 = $h24 % 12 === 0 ? 12 : $h24 % 12;
        return sprintf('%d:%02d %s', $h12, $minutes % 60, $h24 < 12 ? 'AM' : 'PM');
    }

    /** "2:00 PM - 3:00 PM" → [840, 900]. */
    public static function parseRange(?string $value): ?array
    {
        $parts = explode('-', (string) $value);
        if (count($parts) !== 2) {
            return null;
        }
        $start = self::parseClock($parts[0]);
        $end = self::parseClock($parts[1]);
        return $start === null || $end === null ? null : [$start, $end];
    }

    /** "60 Minutes" → 60. */
    public static function parseMinutes(?string $value): ?int
    {
        return preg_match('/(\d+)/', (string) $value, $m) ? (int) $m[1] : null;
    }
}
