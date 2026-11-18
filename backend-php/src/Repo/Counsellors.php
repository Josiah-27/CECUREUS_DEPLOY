<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Config;
use Cecureus\Db;
use Cecureus\Support\Media;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * `doctor_list` (+ doct_language, doctor_timelist, service, booking_appointment).
 * Only the public profile fields are selected — never doc_password, doc_otp, doc_email, doc_mobile,
 * bank_*, doc_pannumber, doc_gst_no, zoomid, doc_fee, doc_address.
 */
final class Counsellors
{
    public const PAGE_SIZE = 20;

    private const ACTIVE = "LOWER(TRIM(d.status)) = 'active' AND TRIM(d.doc_name) <> ''";

    private const FIELDS = "d.doc_id, d.doc_name, d.doc_cityname, d.doc_experiance, d.doc_prelanguage, d.doc_category,
        d.doc_description, d.doct_image,
        (SELECT GROUP_CONCAT(COALESCE(lang.language_name, l.language) SEPARATOR ',')
           FROM doct_language l
           LEFT JOIN language lang ON l.language REGEXP '^[0-9]+$' AND lang.id = l.language
          WHERE l.doc_id = d.doc_id) AS language_list,
        (SELECT COUNT(*) FROM booking_appointment b
          WHERE b.doc_id = d.doc_id AND LOWER(TRIM(b.status)) = 'completed') AS completed_sessions";

    /** @return array{items: list<array<string, mixed>>, nextPage: int|null} */
    public static function list(string $search, string $expertise, int $page): array
    {
        $where = [self::ACTIVE];
        $params = [];
        if ($search !== '') {
            $like = '%' . Text::likeEscape($search) . '%';
            $where[] = '(d.doc_name LIKE ? OR d.doc_category LIKE ? OR d.doc_prelanguage LIKE ? OR d.doc_cityname LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($expertise !== '') {
            $where[] = 'd.doc_category LIKE ?';
            $params[] = '%' . Text::likeEscape($expertise) . '%';
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = Db::all(
            'SELECT ' . self::FIELDS . ' FROM doctor_list d WHERE ' . implode(' AND ', $where)
            . ' ORDER BY d.doc_name, d.doc_id LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset,
            $params,
        );
        $hasMore = count($rows) > self::PAGE_SIZE;
        return [
            'items' => array_map([self::class, 'toCounsellor'], array_slice($rows, 0, self::PAGE_SIZE)),
            'nextPage' => $hasMore ? $page + 1 : null,
        ];
    }

    public static function find(int $docId): ?array
    {
        return Db::one('SELECT ' . self::FIELDS . ' FROM doctor_list d WHERE d.doc_id = ? AND ' . self::ACTIVE, [$docId]);
    }

    public static function toCounsellor(array $row): array
    {
        $languages = Text::csvList($row['language_list'] ?? '');
        $completed = (int) ($row['completed_sessions'] ?? 0);
        return [
            'id' => (string) $row['doc_id'],
            'name' => trim((string) $row['doc_name']),
            'title' => null,
            'city' => Text::orNull($row['doc_cityname']),
            'experienceYears' => Text::firstInt($row['doc_experiance']),
            'languages' => $languages !== [] ? $languages : Text::csvList($row['doc_prelanguage']),
            'expertise' => Text::csvList($row['doc_category']),
            'about' => Text::orNull(Text::plain($row['doc_description'])),
            'imageUrl' => Media::url($row['doct_image'], 'MEDIA_DOCTOR_BASE_URL'),
            'rating' => null,
            'sessionsCount' => $completed > 0 ? $completed : null,
            // Existing bookings only use "voice" and "Video Call" (BACKEND_BLOCKERS B-04).
            'modes' => ['audio', 'video'],
        ];
    }

    /**
     * Bookable 1-hour (service_time) slots between two IST dates, computed from doctor_timelist,
     * minus existing non-cancelled bookings, minus anything starting within BOOKING_LEAD_MINUTES.
     * @return array{timezone: string, days: list<array{date: string, slots: list<array{start: string, end: string}>}>}
     */
    public static function availability(array $doctor, string $from, string $to): array
    {
        [$duration, $buffer] = self::serviceSettings();
        $timelist = Db::one('SELECT * FROM doctor_timelist WHERE doc_id = ? ORDER BY id DESC LIMIT 1', [(string) $doctor['doc_id']]);
        $booked = self::bookedRanges((string) $doctor['doc_id'], $from, $to);
        $earliest = time() + Config::int('BOOKING_LEAD_MINUTES', 60) * 60;

        $days = [];
        for ($ymd = $from; $ymd <= $to; $ymd = Time::addDays($ymd, 1)) {
            $weekday = strtolower(gmdate('l', (int) strtotime($ymd . ' 00:00:00 UTC')));
            $slots = [];
            foreach (self::workingRanges($timelist, $weekday) as [$rangeStart, $rangeEnd]) {
                for ($s = $rangeStart; $s + $duration <= $rangeEnd; $s += $duration + $buffer) {
                    $e = $s + $duration;
                    if (Time::istToTs($ymd, $s) < $earliest) {
                        continue;
                    }
                    foreach ($booked[$ymd] ?? [] as [$bs, $be]) {
                        if ($s < $be && $bs < $e) {
                            continue 2;
                        }
                    }
                    $slots[] = ['start' => Time::istIso($ymd, $s), 'end' => Time::istIso($ymd, $e)];
                }
            }
            if ($slots !== []) {
                $days[] = ['date' => $ymd, 'slots' => $slots];
            }
        }
        return ['timezone' => Time::TIMEZONE, 'days' => $days];
    }

    /**
     * Is $startTs exactly the start of a currently bookable slot? Returns [ymd, startMinutes, durationMinutes].
     * @return array{0: string, 1: int, 2: int}|null
     */
    public static function bookableSlot(array $doctor, int $startTs): ?array
    {
        $ist = Time::istParts($startTs);
        foreach (self::availability($doctor, $ist['ymd'], $ist['ymd'])['days'] as $day) {
            foreach ($day['slots'] as $slot) {
                if (Time::parseIso($slot['start']) === $startTs) {
                    $end = (int) Time::parseIso($slot['end']);
                    return [$ist['ymd'], $ist['minutes'], intdiv($end - $startTs, 60)];
                }
            }
        }
        return null;
    }

    /**
     * Working hours for one weekday from doctor_timelist ("monday", "doc_mondayfirst_from", …).
     * CONFIRM WITH THE WEBSITE TEAM: the exact values stored in the day flag columns. Here a day is
     * off when its flag is one of 0/no/off/closed/false/leave; otherwise its first/second ranges are used.
     * Counsellors without a doctor_timelist row get AVAILABILITY_DEFAULT_HOURS (default 10:00-21:00).
     * @return list<array{0: int, 1: int}>
     */
    private static function workingRanges(?array $timelist, string $weekday): array
    {
        if ($timelist === null) {
            $default = Time::parseRange(Config::get('AVAILABILITY_DEFAULT_HOURS', '10:00-21:00'));
            return $default === null ? [] : [$default];
        }
        $flag = strtolower(trim((string) ($timelist[$weekday] ?? '')));
        if (in_array($flag, ['0', 'no', 'off', 'closed', 'false', 'leave', 'holiday'], true)) {
            return [];
        }
        $ranges = [];
        foreach (['first', 'second'] as $part) {
            $start = Time::parseClock($timelist["doc_{$weekday}{$part}_from"] ?? null);
            $end = Time::parseClock($timelist["doc_{$weekday}{$part}_to"] ?? null);
            if ($start !== null && $end !== null && $end > $start) {
                $ranges[] = [$start, $end];
            }
        }
        return $ranges;
    }

    /** [durationMinutes, bufferMinutes] from the `service` table (defaults 60 / 0). */
    private static function serviceSettings(): array
    {
        $row = Db::one("SELECT service_time, service_buffer FROM service WHERE LOWER(TRIM(status)) = 'active' ORDER BY ser_id LIMIT 1");
        $duration = Time::parseMinutes($row['service_time'] ?? null) ?? 60;
        $buffer = Time::parseMinutes($row['service_buffer'] ?? null) ?? 0;
        return [min(240, max(15, $duration)), min(120, max(0, $buffer))];
    }

    /** @return array<string, list<array{0: int, 1: int}>> ymd → booked minute ranges */
    private static function bookedRanges(string $docId, string $from, string $to): array
    {
        $rows = Db::all(
            'SELECT booknewdate, meating_timelist, meating_starttime, meating_endtime, service_time, status
               FROM booking_appointment WHERE doc_id = ? AND booknewdate BETWEEN ? AND ?',
            [$docId, $from, $to],
        );
        $out = [];
        foreach ($rows as $row) {
            if (Bookings::isCancelledStatus($row['status'])) {
                continue;
            }
            $range = Bookings::minutes($row);
            if ($range !== null) {
                $out[trim((string) $row['booknewdate'])][] = $range;
            }
        }
        return $out;
    }
}
