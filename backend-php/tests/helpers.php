<?php

declare(strict_types=1);

/*
 * Shared helpers for the test files: assertion shortcuts plus seeding and cleanup of throw-away
 * rows. Loaded by tests/run.php; contains no tests itself.
 */

use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Repo\Employees;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throwsApi(int $status, string $code, callable $fn): ApiException
{
    try {
        $fn();
    } catch (ApiException $e) {
        check($e->httpStatus === $status, "expected HTTP {$status}, got {$e->httpStatus}");
        check($e->errorCode === $code, "expected {$code}, got {$e->errorCode}");
        return $e;
    }
    throw new RuntimeException("expected {$status} {$code}, nothing was thrown");
}

/** A minimal request the controllers accept: POST with an optional JSON body, one client IP. */
function makeRequest(string $rawBody = '', array $headers = [], string $remoteAddr = '127.0.0.1'): Request
{
    return new Request('POST', '', [], $headers, $rawBody, $remoteAddr);
}

function jsonRequest(array $body, string $remoteAddr = '127.0.0.1', array $headers = []): Request
{
    return makeRequest((string) json_encode($body), $headers, $remoteAddr);
}

/** Random identifiers so repeated runs and parallel test databases never collide. */
function uniqueEmail(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4)) . '@example.com';
}

/** An Active employee_list row with a random email/mobile. @return array<string, string|null> */
function seedEmployee(): array
{
    $suffix = bin2hex(random_bytes(4));
    $empId = Employees::create([
        'emp_name' => 'Test',
        'emp_lname' => 'User',
        'emp_email' => "test-{$suffix}@example.com",
        'emp_mobile' => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
    ]);
    $employee = Employees::findActiveById($empId);
    check($employee !== null, 'seeded employee not readable');
    return $employee;
}

/** Removes a throw-away account and everything the API wrote for it. */
function deleteEmployee(int $empId): void
{
    Db::run('DELETE FROM mobile_sessions WHERE emp_id = ?', [$empId]);
    Db::run('DELETE FROM mobile_bookings WHERE emp_id = ?', [$empId]);
    Db::run('DELETE FROM booking_appointment WHERE customer_id = ?', [(string) $empId]);
    Db::run('DELETE FROM employee_list WHERE emp_id = ?', [$empId]);
}

/** Clears one named rate-limit bucket by deleting its row. */
function resetLimitBucket(string $name): void
{
    Db::run('DELETE FROM mobile_rate_limits WHERE bucket = ?', [hash('sha256', $name)]);
}
