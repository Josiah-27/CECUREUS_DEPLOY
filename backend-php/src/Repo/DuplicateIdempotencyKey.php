<?php

declare(strict_types=1);

namespace Cecureus\Repo;

/**
 * Thrown when another request has already recorded this (user, Idempotency-Key) pair in
 * mobile_bookings. The caller should replay that booking instead of failing.
 */
final class DuplicateIdempotencyKey extends \RuntimeException
{
}
