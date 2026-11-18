<?php

declare(strict_types=1);

/*
 * End-to-end check of the whole API contract against a running server.
 *
 *   php bin/smoke-test.php http://localhost:8080/api/mobile/v1
 *
 * Needs APP_ENV=development (or staging) with SMS_DRIVER=log and MAIL_DRIVER=log on that server and
 * the same storage/logs folder, because it reads the OTP codes from storage/logs/messages.log.
 * Creates a throw-away account (random email/mobile), books and cancels a slot, then deletes the account.
 */

$base = rtrim($argv[1] ?? 'http://localhost:8080/api/mobile/v1', '/');
$logFile = $argv[2] ?? __DIR__ . '/../storage/logs/messages.log';
$failures = 0;

function call(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = []): array
{
    global $base;
    $ch = curl_init($base . '/' . ltrim($path, '/'));
    $h = ['Accept: application/json'];
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [$status, is_array($json) ? $json : ['raw' => $raw]];
}

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? "  PASS " : "  FAIL ") . $label . ($ok || $detail === null ? '' : ' → ' . json_encode($detail)) . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function codeFor(string $target): string
{
    global $logFile;
    clearstatcache();
    $lines = is_file($logFile) ? file($logFile) : [];
    foreach (array_reverse($lines ?: []) as $line) {
        if (str_contains($line, " to {$target} ") && preg_match('/code is (\d{6})/', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}

$email = 'smoke' . bin2hex(random_bytes(4)) . '@example.com';
$mobile = '+919' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$password = 'Smoke' . random_int(1000, 9999) . 'pass';

echo "Base URL: {$base}\n\n1. Public endpoints\n";
[$s, $r] = call('GET', 'health');
check('GET /health', $s === 200 && ($r['status'] ?? null) === true, $r);
[$s, $r] = call('GET', 'issues');
check('GET /issues (no login)', $s === 200 && is_array($r['data'] ?? null), $r);
[$s, $r] = call('GET', 'languages');
check('GET /languages (no login)', $s === 200 && is_array($r['data'] ?? null), $r);
[$s, $r] = call('GET', 'me');
check('GET /me without token → 401', $s === 401 && ($r['code'] ?? '') === 'UNAUTHENTICATED', $r);
[$s, $r] = call('GET', 'nope');
check('Unknown path → 404 envelope', $s === 404 && ($r['status'] ?? null) === false, $r);

echo "\n2. Sign-up\n";
[$s, $r] = call('POST', 'auth/register/email/request', ['email' => 'not-an-email', 'userType' => 'individual']);
check('Invalid email → 422 INVALID_EMAIL', $s === 422 && ($r['code'] ?? '') === 'INVALID_EMAIL', $r);
[$s, $r] = call('POST', 'auth/register/email/request', ['email' => $email, 'userType' => 'individual']);
check('Email code requested', $s === 200 && isset($r['data']['requestId']), $r);
$emailRequest = $r['data']['requestId'] ?? '';
check('OTP never returned by the API', !str_contains(json_encode($r), codeFor($email) ?: 'xxxxxx'));
[$s, $r] = call('POST', 'auth/register/email/request', ['email' => $email, 'userType' => 'individual']);
check('Resend within 60 s → 429 OTP_COOLDOWN', $s === 429 && ($r['code'] ?? '') === 'OTP_COOLDOWN', $r);
[$s, $r] = call('POST', 'auth/register/email/verify', ['requestId' => $emailRequest, 'otp' => '000000']);
check('Wrong email code → 422', $s === 422, $r);
[$s, $r] = call('POST', 'auth/register/email/verify', ['requestId' => $emailRequest, 'otp' => codeFor($email)]);
check('Email verified → registrationToken', $s === 200 && isset($r['data']['registrationToken']), $r);
$reg = $r['data']['registrationToken'] ?? '';
[$s, $r] = call('POST', 'auth/register/complete', ['registrationToken' => $reg, 'firstName' => 'Smoke', 'password' => $password]);
check('Complete before mobile verified → 422 VERIFICATION_REQUIRED', $s === 422 && ($r['code'] ?? '') === 'VERIFICATION_REQUIRED', $r);
[$s, $r] = call('POST', 'auth/register/phone/request', ['registrationToken' => $reg, 'mobile' => '12345']);
check('Invalid mobile → 422 INVALID_MOBILE', $s === 422 && ($r['code'] ?? '') === 'INVALID_MOBILE', $r);
[$s, $r] = call('POST', 'auth/register/phone/request', ['registrationToken' => $reg, 'mobile' => $mobile]);
check('SMS code requested', $s === 200 && isset($r['data']['requestId']), $r);
$phoneRequest = $r['data']['requestId'] ?? '';
[$s, $r] = call('POST', 'auth/register/phone/verify', ['registrationToken' => $reg, 'requestId' => $phoneRequest, 'otp' => codeFor($mobile)]);
check('Mobile verified', $s === 200 && array_key_exists('data', $r) && $r['data'] === null, $r);
[$s, $r] = call('POST', 'auth/register/complete', ['registrationToken' => $reg, 'firstName' => 'Smoke', 'lastName' => 'Test', 'password' => 'short']);
check('Weak password → 422 WEAK_PASSWORD', $s === 422 && ($r['code'] ?? '') === 'WEAK_PASSWORD', $r);
[$s, $r] = call('POST', 'auth/register/complete', ['registrationToken' => $reg, 'firstName' => 'Smoke', 'lastName' => 'Test', 'password' => $password, 'employeeId' => null]);
check('Account created → 201 AuthSession', $s === 201 && isset($r['data']['accessToken'], $r['data']['user']['id']), $r);
check('User object has no password/OTP', !preg_match('/password|otp/i', json_encode($r['data']['user'] ?? [])), $r['data']['user'] ?? null);
$token = $r['data']['accessToken'] ?? '';
[$s, $r] = call('POST', 'auth/register/email/request', ['email' => $email, 'userType' => 'individual']);
check('Same email again → 409 EMAIL_TAKEN', $s === 409 && ($r['code'] ?? '') === 'EMAIL_TAKEN', $r);

echo "\n3. Sign-in and profile\n";
[$s, $r] = call('POST', 'auth/login', ['userId' => $email, 'password' => 'wrong-password1']);
check('Wrong password → 422 INVALID_CREDENTIALS', $s === 422 && ($r['code'] ?? '') === 'INVALID_CREDENTIALS', $r);
[$s, $r] = call('POST', 'auth/login', ['userId' => 'nobody' . $email, 'password' => 'wrong-password1']);
check('Unknown user → same 422 INVALID_CREDENTIALS', $s === 422 && ($r['code'] ?? '') === 'INVALID_CREDENTIALS', $r);
[$s, $r] = call('POST', 'auth/login', ['userId' => strtoupper($email), 'password' => $password]);
check('Login with email', $s === 200 && isset($r['data']['accessToken']), $r);
[$s, $r] = call('POST', 'auth/login', ['userId' => substr($mobile, 3, 5) . ' ' . substr($mobile, 8), 'password' => $password]);
check('Login with mobile (98765 43210 format)', $s === 200 && isset($r['data']['accessToken']), $r);
[$s, $r] = call('GET', 'me', null, $token);
check('GET /me', $s === 200 && ($r['data']['email'] ?? '') === $email && ($r['data']['mobile'] ?? '') === $mobile, $r);
[$s, $r] = call('PATCH', 'me', ['firstName' => 'Smoke', 'lastName' => 'Tester', 'age' => 17], $token);
check('Age 17 → 422 INVALID_AGE', $s === 422 && ($r['code'] ?? '') === 'INVALID_AGE', $r);
[$s, $r] = call('PATCH', 'me', ['firstName' => 'Smoke', 'lastName' => 'Tester', 'age' => 30], $token);
check('PATCH /me', $s === 200 && ($r['data']['age'] ?? null) === 30 && ($r['data']['lastName'] ?? '') === 'Tester', $r);

echo "\n4. Counsellors and booking\n";
[$s, $r] = call('GET', 'counsellors', null, $token);
check('GET /counsellors', $s === 200 && is_array($r['data']['items'] ?? null), $r);
$items = $r['data']['items'] ?? [];
check('No private counsellor fields leak', !preg_match('/password|pannumber|bank|doc_email|doc_mobile|zoom/i', json_encode($items)), $items);
$doc = $items[0]['id'] ?? null;
if ($doc !== null) {
    [$s, $r] = call('GET', "counsellors/{$doc}", null, $token);
    check('GET /counsellors/{id}', $s === 200 && ($r['data']['id'] ?? '') === $doc, $r);
    $from = gmdate('Y-m-d', time() + 19800);
    $to = gmdate('Y-m-d', time() + 19800 + 13 * 86400);
    [$s, $r] = call('GET', "counsellors/{$doc}/availability?from={$from}&to={$to}", null, $token);
    check('GET availability', $s === 200 && ($r['data']['timezone'] ?? '') === 'Asia/Kolkata', $r);
    $slot = $r['data']['days'][0]['slots'][0]['start'] ?? null;
    if ($slot !== null) {
        $booking = ['counsellorId' => $doc, 'slotStart' => $slot, 'mode' => 'video', 'issueId' => '1', 'language' => 'English', 'notes' => 'Smoke test'];
        [$s, $r] = call('POST', 'bookings', $booking, $token);
        check('Booking without Idempotency-Key → 400', $s === 400, $r);
        $key = 'smoke-' . bin2hex(random_bytes(8));
        [$s, $r] = call('POST', 'bookings', $booking, $token, ['Idempotency-Key' => $key]);
        check('POST /bookings → 201', $s === 201 && isset($r['data']['id']), $r);
        $bookingId = $r['data']['id'] ?? '';
        [$s, $r] = call('POST', 'bookings', $booking, $token, ['Idempotency-Key' => $key]);
        check('Retry with same key → same booking (no duplicate)', $s === 200 && ($r['data']['id'] ?? '') === $bookingId, $r);
        [$s, $r] = call('POST', 'bookings', $booking, $token, ['Idempotency-Key' => $key . 'x']);
        check('Same slot, new key → 409 SLOT_UNAVAILABLE', $s === 409 && ($r['code'] ?? '') === 'SLOT_UNAVAILABLE', $r);
        [$s, $r] = call('GET', 'bookings', null, $token);
        check('GET /bookings lists it', $s === 200 && in_array($bookingId, array_column($r['data']['items'] ?? [], 'id'), true), $r);
        [$s, $r] = call('POST', "bookings/{$bookingId}/cancel", ['reason' => 'Smoke test'], $token);
        check('Cancel → cancelled', $s === 200 && ($r['data']['status'] ?? '') === 'cancelled', $r);
    } else {
        check('Availability has at least one slot (seed data / timelist)', false);
    }
} else {
    check('At least one active counsellor (run sql/dev/001_demo_seed.sql locally)', false);
}

echo "\n5. Content and assessments\n";
[$s, $r] = call('GET', 'articles', null, $token);
check('GET /articles', $s === 200 && is_array($r['data']['items'] ?? null), $r);
$article = $r['data']['items'][0]['id'] ?? null;
if ($article !== null) {
    [$s, $r] = call('GET', "articles/{$article}", null, $token);
    check('GET /articles/{id}', $s === 200 && isset($r['data']['contentHtml']), $r);
}
[$s, $r] = call('GET', 'videos', null, $token);
check('GET /videos', $s === 200 && is_array($r['data']['items'] ?? null), $r);
[$s, $r] = call('GET', 'assessments', null, $token);
check('GET /assessments', $s === 200 && is_array($r['data'] ?? null), $r);
$assessment = $r['data'][0]['id'] ?? null;
if ($assessment !== null) {
    [$s, $r] = call('GET', "assessments/{$assessment}", null, $token);
    check('GET /assessments/{id} (no scores sent)', $s === 200 && !str_contains(json_encode($r), 'tick'), $r);
    $answers = array_map(static fn ($q) => ['questionId' => $q['id'], 'optionId' => end($q['options'])['id']], $r['data']['questions'] ?? []);
    [$s, $r] = call('POST', "assessments/{$assessment}/submit", ['answers' => array_slice($answers, 0, 1)], $token);
    check('Partial answers → 422 INCOMPLETE', $s === 422 && ($r['code'] ?? '') === 'INCOMPLETE', $r);
    [$s, $r] = call('POST', "assessments/{$assessment}/submit", ['answers' => $answers], $token);
    check('Submit → result', $s === 200 && isset($r['data']['percentage'], $r['data']['level']), $r);
}

echo "\n6. Logout and delete\n";
[$s, $r] = call('POST', 'auth/logout', null, $token);
check('Logout', $s === 200, $r);
[$s, $r] = call('GET', 'me', null, $token);
check('Token revoked after logout → 401', $s === 401, $r);
[, $r] = call('POST', 'auth/login', ['userId' => $email, 'password' => $password]);
$token = $r['data']['accessToken'] ?? '';
[$s, $r] = call('DELETE', 'me', null, $token);
check('DELETE /me', $s === 200, $r);
[$s, $r] = call('POST', 'auth/login', ['userId' => $email, 'password' => $password]);
check('Deleted account cannot sign in', $s === 422, $r);

echo PHP_EOL . ($failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
