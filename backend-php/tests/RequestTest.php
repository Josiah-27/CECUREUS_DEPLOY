<?php

declare(strict_types=1);

use Cecureus\Support\Text;

$token = Text::base64url(random_bytes(32));
$shortToken = substr($token, 0, 10);

test('bearer token accepted with exact "Bearer" scheme', function () use ($token): void {
    $request = makeRequest(headers: ['authorization' => 'Bearer ' . $token]);
    check($request->bearerToken() === $token, 'token not returned');
});

test('wrong or missing scheme returns no token', function () use ($token): void {
    check(makeRequest(headers: ['authorization' => 'Basic ' . $token])->bearerToken() === null, 'basic accepted');
    check(makeRequest(headers: ['authorization' => $token])->bearerToken() === null, 'bare token accepted');
    check(makeRequest(headers: ['authorization' => 'Bearer' . $token])->bearerToken() === null, 'missing space accepted');
    check(makeRequest()->bearerToken() === null, 'missing header did not return null');
});

test('malformed tokens return null', function () use ($shortToken): void {
    check(makeRequest(headers: ['authorization' => 'Bearer ' . $shortToken])->bearerToken() === null, 'short token accepted');
    check(makeRequest(headers: ['authorization' => 'Bearer ' . $shortToken . '.sig'])->bearerToken() === null, 'JWT-style token accepted');
    check(makeRequest(headers: ['authorization' => 'Bearer    '])->bearerToken() === null, 'empty token accepted');
});

test('header lookup is case-insensitive', function () use ($token): void {
    $request = makeRequest(headers: ['authorization' => 'bearer ' . $token]);
    check($request->header('Authorization') === 'bearer ' . $token, 'mixed-case name not found');
    check($request->header('x-nothing') === null, 'missing header did not return null');
});

test('empty header values read as null', function (): void {
    check(makeRequest(headers: ['user-agent' => ''])->header('user-agent') === null, 'empty value not null');
});

test('json body is parsed once and cached', function (): void {
    $request = makeRequest('{"a":1}');
    check($request->json() === ['a' => 1], 'body not parsed');
    check($request->json() === ['a' => 1], 'second parse differed');
});

test('invalid or non-object JSON bodies are rejected with 400', function (): void {
    throwsApi(400, 'INVALID_JSON', fn () => makeRequest('not json')->json());
    throwsApi(400, 'INVALID_JSON', fn () => makeRequest('null')->json());
    check(makeRequest('')->json() === [], 'empty body not {}');
});

test('client ip comes from REMOTE_ADDR unless TRUST_PROXY is on', function (): void {
    $request = makeRequest('', ['x-forwarded-for' => '203.0.113.9, 10.0.0.1'], '192.0.2.7');
    check($request->ip() === '192.0.2.7', 'proxy header used without TRUST_PROXY');
    putenv('TRUST_PROXY=true');
    try {
        check($request->ip() === '203.0.113.9', 'first forwarded ip not used');
        $spoofed = makeRequest('', ['x-forwarded-for' => 'not-an-ip'], '192.0.2.7');
        check($spoofed->ip() === '192.0.2.7', 'invalid forwarded ip not ignored');
    } finally {
        putenv('TRUST_PROXY');
    }
});

test('user agent is cut to 255 characters', function (): void {
    $agent = makeRequest('', ['user-agent' => str_repeat('a', 400)])->userAgent();
    check(strlen($agent) === 255, 'agent not truncated');
});
