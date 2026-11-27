<?php

declare(strict_types=1);

use Cecureus\Db;
use Cecureus\Repo\Bookings;
use Cecureus\Repo\Counsellors;
use Cecureus\Support\Time;

/** First slot on $ymd for the doctor, as [startTs, endTs]; null when the day has none. */
function slotOn(string $ymd, array $doctor): ?array
{
    foreach (Counsellors::availability($doctor, $ymd, $ymd)['days'] as $day) {
        $slot = $day['slots'][0] ?? null;
        if ($slot !== null) {
            return [(int) Time::parseIso($slot['start']), (int) Time::parseIso($slot['end'])];
        }
    }
    return null;
}

test('list and find show only active counsellors and public fields', function (): void {
    needDb();
    $inactiveId = Db::value("SELECT doc_id FROM doctor_list WHERE doc_name = 'Inactive Counsellor'");
    if ($inactiveId === null) {
        throw new Skip('demo seed not loaded (sql/dev/001_demo_seed.sql)');
    }
    $items = Counsellors::list('', '', 1)['items'];
    $ids = array_column($items, 'id');
    check(!in_array((string) $inactiveId, $ids, true), 'inactive counsellor listed');
    check(Counsellors::find((int) $inactiveId) === null, 'inactive counsellor found');
    $doctor = Counsellors::find(1);
    if ($doctor === null) {
        throw new Skip('demo counsellor 1 missing');
    }
    $counsellor = Counsellors::toCounsellor($doctor);
    $json = json_encode($counsellor);
    check(!preg_match('/password|pannumber|bank|doc_email|doc_mobile|zoom/i', (string) $json), 'private field leaked');
    check(isset($counsellor['id'], $counsellor['name'], $counsellor['modes']), 'public fields missing');
});

test('counsellor 1 is off on Sundays, the default-hours counsellor is not', function (): void {
    needDb();
    $doc1 = Db::one('SELECT * FROM doctor_list WHERE doc_id = 1');
    $doc2 = Db::one('SELECT * FROM doctor_list WHERE doc_id = 2');
    if ($doc1 === null || $doc2 === null) {
        throw new Skip('demo seed not loaded (sql/dev/001_demo_seed.sql)');
    }
    $today = Time::istParts(time())['ymd'];
    $sunday = null;
    for ($i = 1; $i <= 8; $i++) {
        $ymd = Time::addDays($today, $i);
        if (Time::istParts((int) strtotime($ymd . ' UTC'))['weekday'] === 'sunday') {
            $sunday = $ymd;
            break;
        }
    }
    check($sunday !== null, 'no sunday found in the next week');
    check(slotOn($sunday, $doc1) === null, 'counsellor 1 has Sunday slots');
    $slot = slotOn($sunday, $doc2);
    check($slot !== null, 'default-hours counsellor has no Sunday slots');
});

test('bookableSlot accepts a listed slot and nothing in between', function (): void {
    needDb();
    $doc2 = Db::one('SELECT * FROM doctor_list WHERE doc_id = 2');
    if ($doc2 === null) {
        throw new Skip('demo seed not loaded (sql/dev/001_demo_seed.sql)');
    }
    $today = Time::istParts(time())['ymd'];
    $slot = null;
    for ($i = 1; $i <= 3 && $slot === null; $i++) {
        $slot = slotOn(Time::addDays($today, $i), $doc2);
    }
    check($slot !== null, 'no slot found in the demo data');
    [$start, $end] = $slot;
    $match = Counsellors::bookableSlot($doc2, $start);
    check($match !== null, 'listed slot rejected');
    check($match[2] === intdiv($end - $start, 60), 'slot duration wrong');
    check(Counsellors::bookableSlot($doc2, $start + 1800) === null, 'half-hour offset accepted as a slot');
});

test('a booked slot leaves availability', function (): void {
    needDb();
    $doc1 = Db::one('SELECT * FROM doctor_list WHERE doc_id = 1');
    if ($doc1 === null) {
        throw new Skip('demo seed not loaded (sql/dev/001_demo_seed.sql)');
    }
    $today = Time::istParts(time())['ymd'];
    $slot = null;
    $ymd = null;
    for ($i = 1; $i <= 3 && $slot === null; $i++) {
        $ymd = Time::addDays($today, $i);
        $slot = slotOn($ymd, $doc1);
    }
    check($slot !== null, 'no slot found in the demo data');
    $employee = seedEmployee();
    try {
        [$start, $end] = $slot;
        Bookings::create($employee, $doc1, $ymd, Time::istParts($start)['minutes'], intdiv($end - $start, 60), 'video', null, null, null, 'avail-' . bin2hex(random_bytes(4)));
        $starts = [];
        foreach (Counsellors::availability($doc1, $ymd, $ymd)['days'] as $day) {
            foreach ($day['slots'] as $s) {
                $starts[] = Time::parseIso($s['start']);
            }
        }
        check(!in_array($start, $starts, true), 'booked slot still offered');
        check(Counsellors::bookableSlot($doc1, $start) === null, 'booked slot still bookable');
    } finally {
        deleteEmployee((int) $employee['emp_id']);
    }
});
