<?php

declare(strict_types=1);

namespace Cecureus\Http;

/**
 * Every response uses the envelope the app expects:
 *   success → {"status": true,  "data": …}
 *   error   → {"status": false, "message": "…", "code": "…"}  (+ Retry-After on 429)
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
        public readonly array $headers = [],
    ) {
    }

    public static function ok(mixed $data, int $status = 200): self
    {
        return new self($status, ['status' => true, 'data' => $data]);
    }

    public static function fail(int $status, string $code, string $message, ?int $retryAfter = null): self
    {
        $headers = $retryAfter !== null ? ['Retry-After' => (string) max(1, $retryAfter)] : [];
        return new self($status, ['status' => false, 'message' => $message, 'code' => $code], $headers);
    }

    public static function fromException(ApiException $e): self
    {
        return self::fail($e->httpStatus, $e->errorCode, $e->getMessage(), $e->retryAfterSeconds);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    /** @param array<string, string> $extraHeaders */
    public function send(array $extraHeaders = []): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        foreach (array_merge($this->headers, $extraHeaders) as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->body !== null) {
            // Legacy columns may hold invalid UTF-8; substitute instead of failing the whole response.
            echo json_encode(
                $this->body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        }
    }
}
