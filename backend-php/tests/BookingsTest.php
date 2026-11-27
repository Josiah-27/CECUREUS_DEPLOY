<?php

declare(strict_types=1);

use Cecureus\Repo\Bookings;
use Cecureus\Support\Time;

/** A booking row in the website's column format, one hour IST on $ymd. */
function bookingRow(string $ymd, string $connectoption = 'voice', string $joinUrl = '', string $status = ''): array
{
    return [
        'book_id' => '55',
        'customer_id' => '3',
        'doc_id' => '7',
        'doc_name' => 'Dr Demo ',
        'service_time' => '60 Minutes',
        'issuse_name' => 'Stress',
        'speaking_language' => 'English',
        'connectoption' => $connectoption,
        'booknewdate' => $ymd,
        'meating_timelist' => '2:00 PM - 3:00 PM',
        'meating_starttime' => '2:00 PM ',
        'meating_endtime' => ' 3:00 PM',
        'join_url' => $joinUrl,
        'status' => $status,
        'sms_status' => 'sent',
    ];
}

test('status maps the website values to the contract ones', function (): void {
    check(Bookings::status(bookingRow('2026-02-07')) === 'pending', 'empty join_url not pending');
    check(Bookings::status(bookingRow('2026-02-07', joinUrl: 'https://zoom.example/j/x')) === 'confirmed', 'https join_url not confirmed');
    check(Bookings::status(bookingRow('2026-02-07', joinUrl: 'http://insecure/j')) === 'pending', 'http join_url confirmed');
    check(Bookings::status(bookingRow('2026-02-07', status: 'Cancel')) === 'cancelled', 'Cancel not cancelled');
    check(Bookings::status(bookingRow('2026-02-07', status: ' canceled ')) === 'cancelled', 'spaced canceled not cancelled');
    check(Bookings::status(bookingRow('2026-02-07', status: 'Completed')) === 'completed', 'Completed not completed');
    check(Bookings::status(bookingRow('2026-02-07', status: 'Unattended')) === 'completed', 'Unattended not completed');
});

test('toBooking maps the row into the contract object', function (): void {
    $booking = Bookings::toBooking(bookingRow('2026-02-07', connectoption: 'Video Call'));
    check(is_array($booking), 'row not mapped');
    check($booking['id'] === '55' && $booking['counsellorId'] === '7' && $booking['counsellorName'] === 'Dr Demo', 'ids/names wrong');
    check($booking['start'] === '2026-02-07T14:00:00+05:30' && $booking['end'] === '2026-02-07T15:00:00+05:30', 'IST times wrong');
    check($booking['mode'] === 'video', 'Video Call not video');
    check($booking['issue'] === 'Stress' && $booking['language'] === 'English', 'issue/language wrong');
    check($booking['joinUrl'] === null, 'empty join_url not null');
    check($booking['notification'] === ['sms' => 'sent'], 'sms status wrong');
    check($booking['cancellable'] === false, 'past booking cancellable');
});

test('mode reads the connectoption values the website writes', function (): void {
    check(Bookings::toBooking(bookingRow('2026-02-07', connectoption: 'voice'))['mode'] === 'audio', 'voice not audio');
    check(Bookings::toBooking(bookingRow('2026-02-07', connectoption: 'chat'))['mode'] === 'chat', 'chat not chat');
    check(Bookings::toBooking(bookingRow('2026-02-07', connectoption: 'weird'))['mode'] === 'audio', 'unknown option not audio');
});

test('only attendee https links are sent back', function (): void {
    check(Bookings::toBooking(bookingRow('2026-02-07', joinUrl: 'https://zoom.example/j/abc'))['joinUrl'] === 'https://zoom.example/j/abc', 'https link dropped');
    check(Bookings::toBooking(bookingRow('2026-02-07', joinUrl: '  https://zoom.example/j/abc '))['joinUrl'] === 'https://zoom.example/j/abc', 'whitespace not trimmed');
});

test('cancellable is true only before the cutoff for open bookings', function (): void {
    $future = Time::addDays(Time::istParts(time())['ymd'], 3);
    check(Bookings::toBooking(bookingRow($future, joinUrl: 'https://zoom.example/j'))['cancellable'] === true, 'future confirmed booking not cancellable');
    check(Bookings::toBooking(bookingRow($future, status: 'Cancel'))['cancellable'] === false, 'cancelled booking cancellable');
});

test('zero-length ranges get the default hour', function (): void {
    $row = bookingRow('2026-02-07');
    $row['meating_timelist'] = '3:00 PM - 3:00 PM';
    $booking = Bookings::toBooking($row);
    check($booking !== null && $booking['end'] === '2026-02-07T16:00:00+05:30', 'zero range not extended');
});

test('minutes falls back to the start/end columns when the range is missing', function (): void {
    $row = bookingRow('2026-02-07');
    $row['meating_timelist'] = '';
    check(Bookings::minutes($row) === [840, 900], 'fallback wrong');
    $row['meating_endtime'] = ' unknown';
    check(Bookings::minutes($row) === [840, 900], 'unparsable end did not fall back to service_time');
    check(Bookings::minutes([]) === null, 'empty row not null');
});

test('cancelled statuses are recognised in every spelling the site uses', function (): void {
    foreach (['Cancel', 'cancelled', 'CANCELED', ' Cancel '] as $status) {
        check(Bookings::isCancelledStatus($status), "{$status} not recognised");
    }
    check(!Bookings::isCancelledStatus('Completed') && !Bookings::isCancelledStatus(''), 'open status flagged cancelled');
});
