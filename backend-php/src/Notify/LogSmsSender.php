<?php

declare(strict_types=1);

namespace Cecureus\Notify;

use Cecureus\Support\Log;

/** Development/staging: writes the SMS to storage/logs/messages.log instead of sending it. */
final class LogSmsSender implements SmsSender
{
    public function send(string $mobileE164, string $message, string $templateId): void
    {
        Log::message("SMS to {$mobileE164} (template {$templateId}): {$message}");
    }
}
