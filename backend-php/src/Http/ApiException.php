<?php

declare(strict_types=1);

namespace Cecureus\Http;

/**
 * Throw this from anywhere to answer with an error envelope:
 *   throw new ApiException(422, 'OTP_INVALID', 'Incorrect code. Please try again.');
 * `message` is shown to the user by the app (for 400/409/422), so keep it friendly and never
 * include SQL, stack traces or whether another person's account exists beyond what the contract says.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function validation(string $message, string $code = 'VALIDATION'): self
    {
        return new self(422, $code, $message);
    }
}
