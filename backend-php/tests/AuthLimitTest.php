<?php

declare(strict_types=1);

use Cecureus\Controllers\AuthController;
use Cecureus\Db;
use Cecureus\Repo\Registrations;
use Cecureus\Support\Text;

function takenAnswerLimit(): int
{
    $limit = (int) (new ReflectionClass(AuthController::class))->getConstant('TAKEN_ANSWER_IP_LIMIT_PER_HOUR');
    check($limit >= 1, 'limit constant missing');
    return $limit;
}

/** A client ip unique to this test run, so the per-IP buckets never carry state over. */
function freshProbeIp(): string
{
    return '10.' . random_int(1, 254) . '.' . random_int(1, 254) . '.' . random_int(2, 254);
}

test('the taken-email answer is rate limited per IP', function (): void {
    needDb();
    $employee = seedEmployee();
    $limit = takenAnswerLimit();
    $ip = freshProbeIp();
    try {
        for ($i = 0; $i < $limit; $i++) {
            $e = throwsApi(409, 'EMAIL_TAKEN', fn () => AuthController::registerEmailRequest(jsonRequest(['email' => $employee['emp_email']], $ip)));
            check($e->getMessage() !== '', '409 without message');
        }
        $blocked = throwsApi(429, 'RATE_LIMITED', fn () => AuthController::registerEmailRequest(jsonRequest(['email' => $employee['emp_email']], $ip)));
        check(($blocked->retryAfterSeconds ?? 0) >= 1, 'retry-after missing on the 429');
    } finally {
        resetLimitBucket('email-taken-ip:' . $ip);
        deleteEmployee((int) $employee['emp_id']);
    }
});

test('the taken-mobile answer is rate limited per IP too', function (): void {
    needDb();
    $employee = seedEmployee();
    $limit = takenAnswerLimit();
    $ip = freshProbeIp();
    $email = uniqueEmail('probe');
    $token = Registrations::create($email, 'individual', '');
    $mobile = Text::mobile10((string) $employee['emp_mobile']);
    try {
        for ($i = 0; $i < $limit; $i++) {
            throwsApi(409, 'MOBILE_TAKEN', fn () => AuthController::registerPhoneRequest(jsonRequest(['registrationToken' => $token, 'mobile' => $mobile], $ip)));
        }
        throwsApi(429, 'RATE_LIMITED', fn () => AuthController::registerPhoneRequest(jsonRequest(['registrationToken' => $token, 'mobile' => $mobile], $ip)));
    } finally {
        resetLimitBucket('mobile-taken-ip:' . $ip);
        deleteEmployee((int) $employee['emp_id']);
        Db::run('DELETE FROM mobile_registrations WHERE email = ?', [$email]);
    }
});

test('a different ip still gets the normal taken answers', function (): void {
    needDb();
    $employee = seedEmployee();
    $ip = freshProbeIp();
    try {
        throwsApi(409, 'EMAIL_TAKEN', fn () => AuthController::registerEmailRequest(jsonRequest(['email' => $employee['emp_email']], $ip)));
    } finally {
        resetLimitBucket('email-taken-ip:' . $ip);
        deleteEmployee((int) $employee['emp_id']);
    }
});
