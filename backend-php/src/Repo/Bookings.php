<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Config;
use Cecureus\Db;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * `booking_appointment` (existing, written in the website's format) + `mobile_bookings`
 * (new helper table: Idempotency-Key and SMS status per booking made from the app).
 *
 * Website conventions kept: empty status = upcoming; the team adds join_url when it is confirmed;
 * 'Completed' / 'Unattended' = past; 'Cancel' = cancelled; connectoption 'voice' / 'Video Call'.
 */
final class Bookings
{
    public const PAGE_SIZE = 20;

    private const CONNECT_OPTION = ['audio' => 'voice', 'video' => 'Video Call', 'chat' => 'chat'];

    public static function isCancelledStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['cancel', 'cancelled', 'canceled'], true);
    }

    /** IST start/end minutes of a booking row; null when unparsable. */
    public static function minutes(array $row): ?array
    {
        $range = Time::parseRange($row['meating_timelist'] ?? null);
        if ($range !== null) {
            return $range;
        }
        $start = Time::parseClock($row['meating_starttime'] ?? null);
        if ($start === null) {
            return null;
        }
        $end = Time::parseClock($row['meating_endtime'] ?? null);
        return [$start, $end ?? $start + (Time::parseMinutes($row['service_time'] ?? null) ?? 60)];
    }

    public static function status(array $row): string
    {
        $status = strtolower(trim((string) $row['status']));
        if (self::isCancelledStatus($status)) {
            return 'cancelled';
        }
        if ($status === 'completed' || $status === 'unattended') {
            return 'completed';
        }
        return preg_match('#^https://#i', trim((string) $row['join_url'])) ? 'confirmed' : 'pending';
    }

    /** booking_appointment row (+ sms_status from mobile_bookings) → Booking object; null if unparsable. */
    public static function toBooking(array $row): ?array
    {
        $ymd = trim((string) $row['booknewdate']);
        $range = self::minutes($row);
        if (!Time::isYmd($ymd) || $range === null) {
            return null;
        }
        [$start, $end] = $range;
        if ($end <= $start) {
            $end = $start + 60;
        }
        $status = self::status($row);
        $startTs = Time::istToTs($ymd, $start);
        $connect = strtolower((string) $row['connectoption']);
        $joinUrl = trim((string) $row['join_url']);
        $sms = $row['sms_status'] ?? null;
        $cutoff = Config::int('CANCEL_CUTOFF_MINUTES', 60) * 60;
        return [
            'id' => (string) $row['book_id'],
            'counsellorId' => (string) $row['doc_id'],
            'counsellorName' => trim((string) $row['doc_name']),
            'start' => Time::istIso($ymd, $start),
            'end' => Time::istIso($ymd, $end),
            'mode' => str_contains($connect, 'video') ? 'video' : (str_contains($connect, 'chat') ? 'chat' : 'audio'),
            'issue' => Text::orNull($row['issuse_name']),
            'language' => Text::orNull($row['speaking_language']),
            'status' => $status,
            // Only the attendee link, only HTTPS. Never start_url (that is the host link).
            'joinUrl' => preg_match('#^https://#i', $joinUrl) ? $joinUrl : null,
            'cancellable' => in_array($status, ['pending', 'confirmed'], true) && $startTs - time() >= $cutoff,
            'notification' => in_array($sms, ['sent', 'queued', 'failed', 'not_required'], true) ? ['sms' => $sms] : null,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, nextPage: int|null} */
    public static function forCustomer(int $empId, int $page): array
    {
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = Db::all(
            'SELECT b.*, m.sms_status FROM booking_appointment b
               LEFT JOIN mobile_bookings m ON m.book_id = b.book_id
              WHERE b.customer_id = ?
              ORDER BY b.booknewdate DESC, b.book_id DESC
              LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset,
            [(string) $empId],
        );
        $hasMore = count($rows) > self::PAGE_SIZE;
        $items = array_values(array_filter(array_map([self::class, 'toBooking'], array_slice($rows, 0, self::PAGE_SIZE))));
        return ['items' => $items, 'nextPage' => $hasMore ? $page + 1 : null];
    }

    /** A booking that belongs to this customer (server-side authorisation), or null. */
    public static function findForCustomer(int $bookId, int $empId): ?array
    {
        return Db::one(
            'SELECT b.*, m.sms_status FROM booking_appointment b
               LEFT JOIN mobile_bookings m ON m.book_id = b.book_id
              WHERE b.book_id = ? AND b.customer_id = ?',
            [$bookId, (string) $empId],
        );
    }

    public static function findByIdempotencyKey(int $empId, string $key): ?array
    {
        $bookId = Db::value('SELECT book_id FROM mobile_bookings WHERE emp_id = ? AND idempotency_key = ?', [$empId, $key]);
        return $bookId === null ? null : self::findForCustomer((int) $bookId, $empId);
    }

    /** Inserts the booking in the website's row format and records the Idempotency-Key. Returns book_id. */
    public static function create(
        array $employee,
        array $doctor,
        string $ymd,
        int $startMinutes,
        int $durationMinutes,
        string $mode,
        ?string $issue,
        ?string $language,
        ?string $notes,
        string $idempotencyKey,
    ): int {
        $now = Time::legacyTimestamp(time());
        $from = Time::formatClock($startMinutes);
        $to = Time::formatClock($startMinutes + $durationMinutes);
        $bookId = Db::insert('booking_appointment', [
            'purchase_id' => '',
            'customer_id' => (string) $employee['emp_id'],
            'doc_id' => (string) $doctor['doc_id'],
            'doc_name' => trim((string) $doctor['doc_name']),
            'service_time' => $durationMinutes . ' Minutes',
            'issuse_name' => $issue ?? '',
            'speaking_language' => $language ?? 'English',
            'familyoption' => 'Self',
            'connectoption' => self::CONNECT_OPTION[$mode] ?? 'voice',
            'booknewdate' => $ymd,
            'zoom_id' => '',
            'host_id' => '',
            'start_url' => '',
            'join_url' => '',
            'meating_timelist' => "{$from} - {$to}",
            'meating_starttime' => $from . ' ',
            'meating_endtime' => ' ' . $to,
            'appointment_notes' => $notes ?? '',
            'review_rating' => '0',
            'review_comments' => '',
            'created_at' => $now,
            'updated_at' => $now,
            'status' => '',
            'bookedby' => 'user',
            'reason' => '',
            'canceledby' => '',
            'access_status' => 'Active',
            'role' => '1',
            'popupcount' => 0,
            'remainder_status' => 0,
        ]);
        Db::insert('mobile_bookings', [
            'book_id' => $bookId,
            'emp_id' => (int) $employee['emp_id'],
            'idempotency_key' => $idempotencyKey,
            'sms_status' => 'queued',
            'created_at' => Time::utc(time()),
        ]);
        return $bookId;
    }

    public static function setSmsStatus(int $bookId, string $status): void
    {
        Db::run('UPDATE mobile_bookings SET sms_status = ?, sms_sent_at = ? WHERE book_id = ?', [
            $status,
            $status === 'sent' ? Time::utc(time()) : null,
            $bookId,
        ]);
    }

    public static function cancel(int $bookId, string $reason): void
    {
        Db::update('booking_appointment', [
            'status' => 'Cancel',
            'canceledby' => 'User',
            'reason' => $reason,
            'updated_at' => Time::legacyTimestamp(time()),
        ], 'book_id = ?', [$bookId]);
    }

    /** Account deletion: cancels the user's upcoming bookings. */
    public static function cancelUpcomingFor(int $empId, string $reason): void
    {
        $today = Time::istParts(time())['ymd'];
        $rows = Db::all('SELECT book_id, status FROM booking_appointment WHERE customer_id = ? AND booknewdate >= ?', [(string) $empId, $today]);
        foreach ($rows as $row) {
            $status = strtolower(trim((string) $row['status']));
            if (!self::isCancelledStatus($status) && $status !== 'completed' && $status !== 'unattended') {
                self::cancel((int) $row['book_id'], $reason);
            }
        }
    }
}
