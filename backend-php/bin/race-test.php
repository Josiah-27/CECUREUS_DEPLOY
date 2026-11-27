<?php

declare(strict_types=1);

/*
 * Repro for the parallel Idempotency-Key booking race: two POST /bookings with the same key for
 * two different counsellors, sent at the same time. Before the fix this intermittently answered
 * 500 SERVER_ERROR and left the user with two bookings.
 *
 * The server must run with more than one worker, e.g.:
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 -t public public/index.php
 *   php bin/race-test.php http://127.0.0.1:8080/api/mobile/v1
 *
 * Creates a throw-away account, races the given number of rounds (default 5) and cancels what it
 * booked. Needs the demo seed (sql/dev) so two counsellors with slots exist.
 */

require __DIR__ . '/../src/bootstrap.php';

use Cecureus\Db;
use Cecureus\Repo\Employees;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

$base = rtrim($argv[1] ?? 'http://localhost:8080/api/mobile/v1', '/');
$rounds = max(1, (int) ($argv[2] ?? 5));
$failures = 0;

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === null ? '' : ' -> ' . json_encode($detail)) . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function call(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $ch = curl_init($base . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_values(array_filter([
            'Accept: application/json',
            $body === null ? null : 'Content-Type: application/json',
            $token === null ? null : 'Authorization: Bearer ' . $token,
        ])),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [$status, is_array($json) ? $json : []];
}

/** Two POST /bookings fired at the same time through curl_multi. */
function callBoth(string $token, string $key, array $bodyA, array $bodyB): array
{
    global $base;
    $mh = curl_multi_init();
    $handles = [];
    foreach ([$bodyA, $bodyB] as $body) {
        $ch = curl_init($base . '/bookings');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'Idempotency-Key: ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0);
    $out = [];
    foreach ($handles as $ch) {
        $raw = (string) curl_multi_getcontent($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $json = json_decode($raw, true);
        $out[] = [$status, is_array($json) ? $json : []];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// A throw-away account straight in the database, so the race is not slowed down by the OTP flow.
$suffix = bin2hex(random_bytes(4));
$empId = Employees::create([
    'emp_name' => 'Race',
    'emp_lname' => 'Test',
    'emp_email' => "race-{$suffix}@example.com",
    'emp_mobile' => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
]);
$employee = Employees::findActiveById($empId);
if ($employee === null) {
    echo "could not create the test account\n";
    exit(1);
}
$token = Text::base64url(random_bytes(32));
Db::insert('mobile_sessions', [
    'token_hash' => hash('sha256', $token),
    'emp_id' => $empId,
    'created_at' => Time::utc(time()),
    'expires_at' => Time::utc(time() + 3600),
    'last_used_at' => Time::utc(time()),
    'user_agent' => 'race-test',
]);

[, $r] = call('GET', 'counsellors', null, $token);
$docs = array_slice(array_column($r['data']['items'] ?? [], 'id'), 0, 2);
check('two counsellors available', count($docs) === 2, $r);

$slots = [];
if (count($docs) === 2) {
    $from = gmdate('Y-m-d', time() + 19800 + 86400);
    $to = gmdate('Y-m-d', time() + 19800 + 86400 * 13);
    foreach ($docs as $doc) {
        [, $r] = call('GET', "counsellors/{$doc}/availability?from={$from}&to={$to}", null, $token);
        $slots[$doc] = $r['data']['days'][0]['slots'][0]['start'] ?? null;
    }
    check('both counsellors have a bookable slot', !in_array(null, $slots, true), $slots);
}

if (count($docs) === 2 && !in_array(null, $slots, true)) {
    for ($i = 1; $i <= $rounds; $i++) {
        $key = 'race-' . bin2hex(random_bytes(8));
        [$a, $b] = callBoth(
            $token,
            $key,
            ['counsellorId' => $docs[0], 'slotStart' => $slots[$docs[0]], 'mode' => 'video', 'issueId' => null, 'language' => 'English', 'notes' => null],
            ['counsellorId' => $docs[1], 'slotStart' => $slots[$docs[1]], 'mode' => 'video', 'issueId' => null, 'language' => 'English', 'notes' => null],
        );
        $ids = [$a[1]['data']['id'] ?? null, $b[1]['data']['id'] ?? null];
        check("round {$i}: no 5xx", $a[0] < 500 && $b[0] < 500, [$a[0], $b[0]]);
        check("round {$i}: same booking id in both answers", $ids[0] !== null && $ids[0] === $ids[1], $ids);
        [, $r] = call('GET', 'bookings', null, $token);
        $open = array_values(array_filter(
            $r['data']['items'] ?? [],
            static fn (array $it): bool => ($it['status'] ?? '') !== 'cancelled',
        ));
        check("round {$i}: exactly one open booking for the account", count($open) === 1 && ($open[0]['id'] ?? null) === $ids[0], $open);
        // Best-effort cleanup so the slots are free for the next round.
        foreach (array_column($r['data']['items'] ?? [], 'id') as $id) {
            call('POST', "bookings/{$id}/cancel", ['reason' => 'race test'], $token);
        }
    }
}

Db::run('DELETE FROM mobile_sessions WHERE emp_id = ?', [$empId]);
Db::run('DELETE FROM employee_list WHERE emp_id = ?', [$empId]);

echo PHP_EOL . ($failures === 0 ? "ALL ROUNDS CLEAN\n" : "{$failures} CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
