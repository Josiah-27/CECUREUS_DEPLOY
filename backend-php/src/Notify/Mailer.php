<?php

declare(strict_types=1);

namespace Cecureus\Notify;

interface Mailer
{
    /** Sends a plain-text email. Throws on failure. */
    public function send(string $to, string $subject, string $text): void;
}
