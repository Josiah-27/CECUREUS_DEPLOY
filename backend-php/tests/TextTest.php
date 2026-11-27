<?php

declare(strict_types=1);

use Cecureus\Support\Text;

test('clean strips control chars and tags, collapses whitespace, cuts to max', function (): void {
    check(Text::clean("  a\x01b<c>  \n  d  ", 100) === 'abc d', 'control chars or <> not removed');
    check(Text::clean('  hello   world ', 100) === 'hello world', 'whitespace not collapsed');
    check(Text::clean(str_repeat('x', 300), 100) === str_repeat('x', 100), 'not cut to max');
    check(Text::clean(42, 10) === '42', 'numbers not stringified');
    check(Text::clean(null, 10) === '', 'null not turned into empty string');
    check(Text::clean(['array'], 10) === '', 'array not turned into empty string');
});

test('isEmail accepts normal emails and rejects broken ones', function (): void {
    check(Text::isEmail('user@example.com'), 'normal email rejected');
    check(Text::isEmail('user+tag@sub.example.co.in'), 'valid email rejected');
    check(!Text::isEmail('not-an-email'), 'garbage accepted');
    check(!Text::isEmail('user@'), 'domainless accepted');
    check(!Text::isEmail('@example.com'), 'localless accepted');
    check(!Text::isEmail(str_repeat('a', 260) . '@example.com'), 'overlong email accepted');
});

test('normalizeMobile handles the legacy Indian formats', function (): void {
    check(Text::normalizeMobile('9876543210') === '+919876543210', 'plain 10 digits');
    check(Text::normalizeMobile('91-9876543210') === '+919876543210', '91- prefix');
    check(Text::normalizeMobile('09876543210') === '+919876543210', 'leading 0');
    check(Text::normalizeMobile('98765 43210') === '+919876543210', 'spaced format');
    check(Text::normalizeMobile('+919876543210') === '+919876543210', 'e164 passthrough');
    check(Text::normalizeMobile('+91 98765 43210') === '+919876543210', 'e164 with spaces');
    check(Text::normalizeMobile('12345') === null, 'too short accepted');
    check(Text::normalizeMobile('5876543210') === null, 'non 6-9 leading digit accepted');
    check(Text::normalizeMobile('') === null, 'empty accepted');
    check(Text::normalizeMobile(null) === null, 'null accepted');
});

test('mobile and email masking keep the useful part only', function (): void {
    check(Text::maskMobile('+919876543210') === '+91******3210', 'mobile mask wrong');
    check(Text::maskEmail('user@example.com') === 'u***@example.com', 'email mask wrong');
    check(Text::maskEmail('@example.com') === '***@example.com', 'empty local part mask wrong');
});

test('isId accepts positive ids only', function (): void {
    check(Text::isId('1') && Text::isId('123456789') && Text::isId(7), 'valid id rejected');
    check(!Text::isId('0'), 'zero accepted');
    check(!Text::isId('-1'), 'negative accepted');
    check(!Text::isId('07'), 'leading zero accepted');
    check(!Text::isId('abc'), 'letters accepted');
    check(!Text::isId(1.5), 'float accepted');
});

test('base64url encodes without padding or special chars', function (): void {
    check(Text::base64url("a>\?b/\c+") === 'YT5cP2IvXGMr', 'unexpected base64url output');
    check(!str_contains(Text::base64url(random_bytes(64)), '+') && !str_contains(Text::base64url(random_bytes(64)), '/'), 'url-unsafe chars produced');
});

test('csvList trims, drops empties and de-duplicates', function (): void {
    check(Text::csvList('A, B ,A,,C') === ['A', 'B', 'C'], 'csv list wrong');
    check(Text::csvList(null) === [], 'null not empty list');
});

test('plain turns html into one plain-text line', function (): void {
    check(Text::plain('<p>Hello &amp; welcome</p><p>Second</p>') === 'Hello & welcomeSecond', 'html not converted to plain text');
    check(Text::plain(null) === '', 'null not empty');
});

test('firstInt reads the first number', function (): void {
    check(Text::firstInt('28 Years') === 28, 'years not parsed');
    check(Text::firstInt('age: 30y 2m') === 30, 'first number not taken');
    check(Text::firstInt('none') === null, 'missing number not null');
});
