<?php

declare(strict_types=1);

use Cecureus\Db;
use Cecureus\Repo\Bookings;
use Cecureus\Repo\Counsellors;
use Cecureus\Repo\DuplicateIdempotencyKey;
use Cecureus\Support\Time;

/** First bookable slot for a seeded counsellor within the next days (IST); [ymd, startTs, endTs, doctor row]. */
function firstSlot(array $doctor): ?array
{
    $ymd = Time::addDays(Time::istParts(time())['ymd'], 1);
    foreach (Counsellors::availability($doctor, $ymd, Time::addDays($ymd, 2))['days'] as $day) {
        $slot = $day['slots'][0] ?? null;
        if ($slot !== null) {
            return [$day['date'], (int) Time::parseIso($slot['start']), (int) Time::parseIso($slot['end']), $doctor];
        }
    }
    return null;
}

test('the second booking with the same key loses the claim and leaves no orphan row', function (): void {
    needDb();
    $doc1 = Db::one('SELECT * FROM doctor_list WHERE doc_id = 1');
    $doc2 = Db::one('SELECT * FROM doctor_list WHERE doc_id = 2');
    if ($doc1 === null || $doc2 === null) {
        throw new Skip('demo seed not loaded (sql/dev/001_demo_seed.sql)');
    }
    $slot1 = firstSlot($doc1);
    $slot2 = firstSlot($doc2);
    if ($slot1 === null || $slot2 === null) {
        throw new Skip('no bookable slots in the demo data');
    }
    $employee = seedEmployee();
    $key = 'race-' . bin2hex(random_bytes(8));
    try {
        $bookId1 = Bookings::create($employee, $doc1, $slot1[0], Time::istParts($slot1[1])['minutes'], intdiv($slot1[2] - $slot1[1], 60), 'video', null, null, null, $key);
        check($bookId1 > 0, 'first booking not created');

        $lost = null;
        try {
            // Same user, same key, different counsellor: what two parallel requests look like
            // after both passed the replay check under their own counsellor lock.
            Bookings::create($employee, $doc2, $slot2[0], Time::istParts($slot2[1])['minutes'], intdiv($slot2[2] - $slot2[1], 60), 'video', null, null, null, $key);
        } catch (DuplicateIdempotencyKey $caught) {
            $lost = $caught;
        }
        check($lost !== null, 'duplicate key did not raise DuplicateIdempotencyKey');

        $claims = (int) Db::value('SELECT COUNT(*) FROM mobile_bookings WHERE emp_id = ? AND idempotency_key = ?', [(int) $employee['emp_id'], $key]);
        check($claims === 1, "expected one claim row, found {$claims}");
        $claimPointsAt = (int) Db::value('SELECT book_id FROM mobile_bookings WHERE emp_id = ? AND idempotency_key = ?', [(int) $employee['emp_id'], $key]);
        check($claimPointsAt === $bookId1, 'claim does not point at the winning booking');
        $rowsForSecond = (int) Db::value('SELECT COUNT(*) FROM booking_appointment WHERE customer_id = ? AND doc_id = ?', [(string) $employee['emp_id'], (string) $doc2['doc_id']]);
        check($rowsForSecond === 0, 'loser booking row left behind (slot stays blocked)');
        $rowsForFirst = (int) Db::value('SELECT COUNT(*) FROM booking_appointment WHERE book_id = ?', [$bookId1]);
        check($rowsForFirst === 1, 'winner booking row lost');

        $stillFree = Counsellors::bookableSlot($doc2, $slot2[1]);
        check($stillFree !== null, 'loser slot stayed blocked after cleanup');
    } finally {
        deleteEmployee((int) $employee['emp_id']);
    }
});

test('a replay with the same key returns the original booking', function (): void {
    needDb();
    $doc1 = Db::one('SELECT * FROM doctor_list WHERE doc_id = 1');
    if ($doc1 === null) {
        throw new Skip('demo seed not loaded (sql/dev/001_demo_seed.sql)');
    }
    $slot = firstSlot($doc1);
    if ($slot === null) {
        throw new Skip('no bookable slots in the demo data');
    }
    $employee = seedEmployee();
    $key = 'replay-' . bin2hex(random_bytes(8));
    try {
        $bookId = Bookings::create($employee, $doc1, $slot[0], Time::istParts($slot[1])['minutes'], intdiv($slot[2] - $slot[1], 60), 'video', null, null, null, $key);
        $replay = Bookings::findByIdempotencyKey((int) $employee['emp_id'], $key);
        check($replay !== null, 'replay lookup empty');
        check((int) $replay['book_id'] === $bookId, 'replay points at the wrong booking');
        $booking = Bookings::toBooking($replay);
        check($booking !== null && $booking['id'] === (string) $bookId, 'replay not convertible');
        check($booking['counsellorId'] === (string) $doc1['doc_id'], 'replay counsellor wrong');
    } finally {
        deleteEmployee((int) $employee['emp_id']);
    }
});
