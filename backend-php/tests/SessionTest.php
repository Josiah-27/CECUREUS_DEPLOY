<?php

declare(strict_types=1);

use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Repo\Employees;
use Cecureus\Security\Sessions;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

test('created sessions authenticate and return the user', function (): void {
    needDb();
    $employee = seedEmployee();
    try {
        $request = makeRequest();
        $session = Sessions::create($employee, $request);
        check(isset($session['accessToken'], $session['expiresAt']), 'session shape wrong');
        check(!isset($session['user']['emp_password']), 'password leaked in user object');
        $row = Sessions::authenticate(makeRequest(headers: ['authorization' => 'Bearer ' . $session['accessToken']]));
        check((int) $row['emp_id'] === (int) $employee['emp_id'], 'authenticated as the wrong user');
    } finally {
        deleteEmployee((int) $employee['emp_id']);
    }
});

test('missing or broken tokens are unauthenticated', function (): void {
    needDb();
    catchApi(static function (): void {
        Sessions::authenticate(makeRequest());
    });
    $token = Text::base64url(random_bytes(32));
    catchApi(static function () use ($token): void {
        Sessions::authenticate(makeRequest(headers: ['authorization' => 'Bearer ' . $token]));
    });
    catchApi(static function (): void {
        $short = substr(Text::base64url(random_bytes(32)), 0, 10);
        Sessions::authenticate(makeRequest(headers: ['authorization' => 'Bearer ' . $short]));
    });
});

test('revoked and expired sessions cannot sign in', function (): void {
    needDb();
    $employee = seedEmployee();
    try {
        $request = makeRequest();
        $token = Sessions::create($employee, $request)['accessToken'];
        Sessions::revoke($token);
        catchApi(static function () use ($token): void {
            Sessions::authenticate(makeRequest(headers: ['authorization' => 'Bearer ' . $token]));
        });
        $token2 = Sessions::create($employee, $request)['accessToken'];
        $hash = hash('sha256', $token2);
        Db::run('UPDATE mobile_sessions SET expires_at = ? WHERE token_hash = ?', [Time::utc(time() - 60), $hash]);
        catchApi(static function () use ($token2): void {
            Sessions::authenticate(makeRequest(headers: ['authorization' => 'Bearer ' . $token2]));
        });
    } finally {
        deleteEmployee((int) $employee['emp_id']);
    }
});

test('deactivated accounts lose all their sessions', function (): void {
    needDb();
    $employee = seedEmployee();
    try {
        $token = Sessions::create($employee, makeRequest())['accessToken'];
        Employees::update((int) $employee['emp_id'], ['status' => 'DeActive']);
        catchApi(static function () use ($token): void {
            Sessions::authenticate(makeRequest(headers: ['authorization' => 'Bearer ' . $token]));
        });
        $left = (int) Db::value('SELECT COUNT(*) FROM mobile_sessions WHERE emp_id = ? AND revoked_at IS NULL', [(int) $employee['emp_id']]);
        check($left === 0, 'sessions not revoked after deactivation');
    } finally {
        deleteEmployee((int) $employee['emp_id']);
    }
});

/** Runs $fn, returns the ApiException it throws; fails the test when nothing is thrown. */
function catchApi(callable $fn): ApiException
{
    try {
        $fn();
    } catch (ApiException $e) {
        check($e->httpStatus === 401, 'expected 401, got ' . $e->httpStatus);
        return $e;
    }
    throw new RuntimeException('expected an ApiException, nothing was thrown');
}
