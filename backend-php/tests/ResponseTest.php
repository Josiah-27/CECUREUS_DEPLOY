<?php

declare(strict_types=1);

use Cecureus\Http\ApiException;
use Cecureus\Http\Response;

test('ok wraps the data in the success envelope', function (): void {
    $response = Response::ok(['a' => 1]);
    check($response->status === 200, 'default status not 200');
    check($response->body === ['status' => true, 'data' => ['a' => 1]], 'envelope wrong');
    $created = Response::ok(null, 201);
    check($created->status === 201 && $created->body === ['status' => true, 'data' => null], 'custom status lost');
});

test('fail builds the error envelope and only adds Retry-After when given', function (): void {
    $response = Response::fail(429, 'RATE_LIMITED', 'Too many.');
    check($response->status === 429, 'status lost');
    check($response->body === ['status' => false, 'message' => 'Too many.', 'code' => 'RATE_LIMITED'], 'envelope wrong');
    check(!isset($response->headers['Retry-After']), 'retry header added without a wait');
    $withWait = Response::fail(429, 'RATE_LIMITED', 'Too many.', 90);
    check(($withWait->headers['Retry-After'] ?? '') === '90', 'retry header value wrong');
    check((Response::fail(429, 'X', 'm', 0)->headers['Retry-After'] ?? '') === '1', 'zero wait not clamped to one second');
});

test('fromException carries status, code, message and wait', function (): void {
    $plain = Response::fromException(new ApiException(404, 'NOT_FOUND', 'Booking not found.'));
    check($plain->status === 404 && $plain->body['code'] === 'NOT_FOUND' && $plain->body['message'] === 'Booking not found.', 'mapping wrong');
    check(!isset($plain->headers['Retry-After']), 'plain error got a wait');
    $waited = Response::fromException(new ApiException(429, 'OTP_COOLDOWN', 'Wait.', 45));
    check(($waited->headers['Retry-After'] ?? '') === '45', 'wait lost in mapping');
});

test('noContent answers 204 with no body', function (): void {
    $response = Response::noContent();
    check($response->status === 204 && $response->body === null, 'noContent not empty');
});
