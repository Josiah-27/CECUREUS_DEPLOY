<?php

declare(strict_types=1);

use Cecureus\Db;
use Cecureus\Security\RateLimiter;
use Cecureus\Support\Time;

test('consume counts hits and throws 429 past the limit, with a Retry-After', function (): void {
    needDb();
    $name = 'test-rl-' . bin2hex(random_bytes(4));
    try {
        RateLimiter::consume($name, 2, 60);
        RateLimiter::consume($name, 2, 60);
        $e = null;
        try {
            RateLimiter::consume($name, 2, 60);
        } catch (Cecureus\Http\ApiException $caught) {
            $e = $caught;
        }
        check($e !== null, 'third call not limited');
        check($e->httpStatus === 429 && $e->errorCode === 'RATE_LIMITED', 'wrong limit response');
        check(($e->retryAfterSeconds ?? 0) >= 1, 'retry-after missing');
    } finally {
        RateLimiter::reset($name);
    }
});

test('reset clears the bucket', function (): void {
    needDb();
    $name = 'test-rl-' . bin2hex(random_bytes(4));
    try {
        RateLimiter::consume($name, 1, 60);
        RateLimiter::reset($name);
        RateLimiter::consume($name, 1, 60);
    } finally {
        RateLimiter::reset($name);
    }
});

test('hit reports the count in the window', function (): void {
    needDb();
    $name = 'test-rl-' . bin2hex(random_bytes(4));
    try {
        check(RateLimiter::hit($name, 60)['hits'] === 1, 'first hit not 1');
        check(RateLimiter::hit($name, 60)['hits'] === 2, 'second hit not 2');
    } finally {
        RateLimiter::reset($name);
    }
});

test('an old window restarts from one', function (): void {
    needDb();
    $name = 'test-rl-' . bin2hex(random_bytes(4));
    try {
        RateLimiter::hit($name, 60);
        RateLimiter::hit($name, 60);
        $bucket = hash('sha256', $name);
        Db::run('UPDATE mobile_rate_limits SET window_start = ? WHERE bucket = ?', [Time::utc(time() - 3600), $bucket]);
        check(RateLimiter::hit($name, 60)['hits'] === 1, 'stale window not restarted');
    } finally {
        RateLimiter::reset($name);
    }
});

test('check throws when the window is full but does not count the call', function (): void {
    needDb();
    $name = 'test-rl-' . bin2hex(random_bytes(4));
    try {
        RateLimiter::check($name, 5, 60, 'TEST_LOCKED', 'blocked');
        RateLimiter::hit($name, 60);
        RateLimiter::hit($name, 60);
        RateLimiter::hit($name, 60);
        RateLimiter::hit($name, 60);
        RateLimiter::hit($name, 60);
        $e = null;
        try {
            RateLimiter::check($name, 5, 60, 'TEST_LOCKED', 'blocked');
        } catch (Cecureus\Http\ApiException $caught) {
            $e = $caught;
        }
        check($e !== null && $e->errorCode === 'TEST_LOCKED', 'full window not detected');
        check(RateLimiter::hit($name, 60)['hits'] === 6, 'check changed the counter');
    } finally {
        RateLimiter::reset($name);
    }
});
