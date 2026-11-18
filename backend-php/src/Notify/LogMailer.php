<?php

declare(strict_types=1);

namespace Cecureus\Notify;

use Cecureus\Support\Log;

/** Development/staging: writes the email to storage/logs/messages.log instead of sending it. */
final class LogMailer implements Mailer
{
    public function send(string $to, string $subject, string $text): void
    {
        Log::message("EMAIL to {$to} | {$subject} | {$text}");
    }
}
