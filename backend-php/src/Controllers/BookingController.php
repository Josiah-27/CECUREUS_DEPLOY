<?php

declare(strict_types=1);

namespace Cecureus\Controllers;

use Cecureus\Config;
use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Notify\Channels;
use Cecureus\Repo\Bookings;
use Cecureus\Repo\Counsellors;
use Cecureus\Repo\Employees;
use Cecureus\Repo\Organisations;
use Cecureus\Security\RateLimiter;
use Cecureus\Security\Sessions;
use Cecureus\Support\Log;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * Booking rules (docs/API_CONTRACT.md §5):
 *  1. Same (user, Idempotency-Key) → the original booking is returned; no second booking, no second SMS.
 *  2. Slot check + insert happen under a per-counsellor MySQL lock, so two people can't take one slot.
 *  3. Corporate entitlement is checked.
 *  4. The admin SMS is sent AFTER the booking is saved; an SMS failure never fails the booking.
 */
final class BookingController
{
    /** POST /bookings  header Idempotency-Key  {counsellorId, slotStart, mode, issueId, language, notes} */
    public static function create(Request $request): Response
    {
        $employee = Sessions::authenticate($request);
        $empId = (int) $employee['emp_id'];
        $key = $request->header('idempotency-key');
        if ($key === null || !preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            throw new ApiException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key header required.');
        }
        $replay = Bookings::findByIdempotencyKey($empId, $key);
        if ($replay !== null) {
            return Response::ok(Bookings::toBooking($replay));
        }
        RateLimiter::consume("booking:{$empId}", 10, 3600);

        $body = $request->json();
        $doctor = CounsellorController::findOr404($body['counsellorId'] ?? '');
        $mode = $body['mode'] ?? '';
        if (!in_array($mode, Counsellors::toCounsellor($doctor)['modes'], true)) {
            throw ApiException::validation('This session type is not available for this counsellor.', 'INVALID_MODE');
        }
        $startTs = Time::parseIso($body['slotStart'] ?? null);
        if ($startTs === null) {
            throw ApiException::validation('Choose a valid time slot.', 'INVALID_SLOT');
        }
        $issue = null;
        if (Text::isId($body['issueId'] ?? null)) {
            $issue = Db::value(
                "SELECT TRIM(issuse_name) FROM issuse_list WHERE issuse_id = ? AND LOWER(TRIM(status)) = 'active'",
                [(int) $body['issueId']],
            );
        }
        $language = Text::orNull(Text::clean($body['language'] ?? '', 50));
        $notes = Text::orNull(Text::clean($body['notes'] ?? '', 1000));

        if (Employees::isCorporate($employee)) {
            $remaining = Organisations::sessionsRemaining($employee);
            if ($remaining !== null && $remaining <= 0) {
                throw new ApiException(409, 'NO_SESSIONS_LEFT', "You have used all the sessions included in your company's plan this year.");
            }
        } elseif (!Config::bool('ALLOW_INDIVIDUAL_BOOKINGS', true)) {
            // Product decision pending (BACKEND_BLOCKERS B-14).
            throw new ApiException(403, 'BOOKING_NOT_AVAILABLE', 'Booking is not available for individual accounts yet.');
        }

        $lock = 'cecureus_booking_doc_' . $doctor['doc_id'];
        if (!Db::lock($lock)) {
            throw new ApiException(503, 'BUSY', 'The server is busy. Please try again.');
        }
        try {
            // Re-check inside the lock: a retry with the same key may have just finished.
            $replay = Bookings::findByIdempotencyKey($empId, $key);
            if ($replay !== null) {
                return Response::ok(Bookings::toBooking($replay));
            }
            $slot = Counsellors::bookableSlot($doctor, $startTs);
            if ($slot === null) {
                throw new ApiException(409, 'SLOT_UNAVAILABLE', 'This time slot is no longer available. Please choose another.');
            }
            [$ymd, $startMinutes, $duration] = $slot;
            $bookId = Bookings::create($employee, $doctor, $ymd, $startMinutes, $duration, $mode, $issue, $language, $notes, $key);
        } finally {
            Db::unlock($lock);
        }

        Bookings::setSmsStatus($bookId, self::notifyAdmin($employee, $doctor, $ymd, $startMinutes, $mode));
        $booking = Bookings::findForCustomer($bookId, $empId);
        if ($booking === null) {
            throw new \RuntimeException("Booking {$bookId} not readable after insert");
        }
        return Response::ok(Bookings::toBooking($booking), 201);
    }

    /** GET /bookings?page= — only the signed-in user's bookings. */
    public static function index(Request $request): Response
    {
        $employee = Sessions::authenticate($request);
        return Response::ok(Bookings::forCustomer((int) $employee['emp_id'], CounsellorController::page($request)));
    }

    /** POST /bookings/{id}/cancel {reason} */
    public static function cancel(Request $request, array $params): Response
    {
        $employee = Sessions::authenticate($request);
        $empId = (int) $employee['emp_id'];
        RateLimiter::consume("cancel:{$empId}", 10, 3600);
        $id = $params['id'] ?? '';
        $row = Text::isId($id) ? Bookings::findForCustomer((int) $id, $empId) : null;
        $booking = $row === null ? null : Bookings::toBooking($row);
        if ($booking === null) {
            throw ApiException::notFound('Booking not found.');
        }
        if (!$booking['cancellable']) {
            throw new ApiException(409, 'NOT_CANCELLABLE', 'This session can no longer be cancelled.');
        }
        $reason = Text::clean($request->json()['reason'] ?? '', 250);
        Bookings::cancel((int) $id, $reason !== '' ? $reason : 'Cancelled by user');
        return Response::ok(Bookings::toBooking(Bookings::findForCustomer((int) $id, $empId) ?? $row));
    }

    /**
     * SMS to the CecureUs team (ADMIN_SMS_MOBILE, default +91 72005 00221). Text must match the
     * DLT-approved template SMS_BOOKING_TEMPLATE_ID. Deliberately excludes the issue/notes (health data).
     */
    private static function notifyAdmin(array $employee, array $doctor, string $ymd, int $startMinutes, string $mode): string
    {
        $template = Config::get('SMS_BOOKING_TEMPLATE_ID', '') ?? '';
        $admin = Text::normalizeMobile(Config::get('ADMIN_SMS_MOBILE', '+917200500221'));
        if ($template === '' || $admin === null) {
            return 'not_required';
        }
        $message = strtr(
            Config::get('SMS_BOOKING_MESSAGE', 'New CecureUs booking: {name} with {counsellor} on {date} at {time} ({mode}).') ?? '',
            [
                '{name}' => trim($employee['emp_name'] . ' ' . $employee['emp_lname']),
                '{counsellor}' => trim((string) $doctor['doc_name']),
                '{date}' => gmdate('d-m-Y', (int) strtotime($ymd . ' 00:00:00 UTC')),
                '{time}' => Time::formatClock($startMinutes),
                '{mode}' => $mode === 'video' ? 'Video' : 'Audio',
            ],
        );
        try {
            Channels::sms()->send($admin, $message, $template);
            return 'sent';
        } catch (\Throwable $e) {
            Log::error('Booking SMS failed', $e);
            return 'failed';
        }
    }
}
