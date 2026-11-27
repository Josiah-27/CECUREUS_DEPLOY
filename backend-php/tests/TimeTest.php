<?php

declare(strict_types=1);

use Cecureus\Support\Time;

test('clock strings parse in both formats', function (): void {
    check(Time::parseClock('6:00 PM') === 1080, '6:00 PM wrong');
    check(Time::parseClock('06:00PM') === 1080, '06:00PM wrong');
    check(Time::parseClock('12:05 AM') === 5, '12:05 AM wrong');
    check(Time::parseClock('18:00') === 1080, '24h format wrong');
    check(Time::parseClock('10:00') === 600, '10:00 wrong');
    check(Time::parseClock('13:00 PM') === null, 'nonsense am/pm accepted');
    check(Time::parseClock('25:00') === null, 'hour 25 accepted');
    check(Time::parseClock('nope') === null, 'garbage accepted');
    check(Time::parseClock(null) === null, 'null accepted');
});

test('formatClock writes the website format', function (): void {
    check(Time::formatClock(1080) === '6:00 PM', '1080 wrong');
    check(Time::formatClock(600) === '10:00 AM', '600 wrong');
    check(Time::formatClock(0) === '12:00 AM', 'midnight wrong');
    check(Time::formatClock(725) === '12:05 PM', '12:05 PM wrong');
    check(Time::formatClock(Time::parseClock('2:00 PM')) === '2:00 PM', 'roundtrip changed the value');
});

test('parseRange splits a timelist range', function (): void {
    check(Time::parseRange('2:00 PM - 3:00 PM') === [840, 900], 'pm range wrong');
    check(Time::parseRange('10:00 - 11:30') === [600, 690], '24h range wrong');
    check(Time::parseRange('2:00 PM') === null, 'one-sided range accepted');
    check(Time::parseRange('x - y') === null, 'garbage range accepted');
});

test('utc helpers roundtrip and ist conversions keep the offset', function (): void {
    $ts = 1770000000;
    check(Time::utc($ts) === gmdate('Y-m-d H:i:s', $ts), 'utc format wrong');
    check(Time::fromUtc(Time::utc($ts)) === $ts, 'utc roundtrip off');
    check(Time::fromUtc(null) === 0, 'null not 0');
    check(Time::istToTs('2026-02-07', 840) === (int) strtotime('2026-02-07 00:00:00 UTC') + 840 * 60 - Time::IST_OFFSET, 'ist timestamp wrong');
    check(Time::istIso('2026-02-07', 840) === '2026-02-07T14:00:00+05:30', 'ist iso wrong');
    check(Time::istParts((int) strtotime('2026-02-07 06:39:31 UTC')) === ['ymd' => '2026-02-07', 'minutes' => 729, 'weekday' => 'saturday'], 'ist parts wrong');
});

test('parseIso accepts offsets and rejects everything else', function (): void {
    check(Time::parseIso('2026-11-02T10:00:00+05:30') === (int) strtotime('2026-11-02T04:30:00+00:00'), 'offset iso wrong');
    check(Time::parseIso('2026-11-02T10:00Z') === (int) strtotime('2026-11-02T10:00:00+00:00'), 'z iso wrong');
    check(Time::parseIso('2026-11-02T10:00:00.123456+00:00') !== null, 'fractional iso rejected');
    check(Time::parseIso('2026-11-02 10:00:00') === null, 'non-iso format accepted');
    check(Time::parseIso('tomorrow') === null, 'english date accepted');
    check(Time::parseIso(12345) === null, 'int accepted');
    check(Time::parseIso(null) === null, 'null accepted');
});

test('isYmd validates real calendar dates', function (): void {
    check(Time::isYmd('2026-02-07'), 'valid date rejected');
    check(!Time::isYmd('2026-02-30'), '30 feb accepted');
    check(!Time::isYmd('07-02-2026'), 'swapped format accepted');
    check(!Time::isYmd('2026-2-7'), 'unpadded accepted');
    check(!Time::isYmd(null), 'null accepted');
});

test('legacy IST timestamps format and parse back', function (): void {
    $ts = (int) strtotime('2026-02-07 06:39:31 UTC');
    check(Time::legacyTimestamp($ts) === '07-02-2026 12:09:31 PM', 'legacy format wrong');
    check(Time::legacyToIso('07-02-2026 12:09:31 PM') === '2026-02-07T12:09:31+05:30', 'legacy parse wrong');
    check(Time::legacyToIso('not a date') === null, 'garbage accepted');
});

test('addDays and daysBetween do plain UTC day math', function (): void {
    check(Time::addDays('2026-02-07', 21) === '2026-02-28', 'addDays wrong');
    check(Time::addDays('2026-02-07', -7) === '2026-01-31', 'negative addDays wrong');
    check(Time::daysBetween('2026-02-07', '2026-03-10') === 31, 'daysBetween wrong');
});
