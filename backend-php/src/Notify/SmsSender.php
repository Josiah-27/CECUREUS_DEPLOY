<?php

declare(strict_types=1);

namespace Cecureus\Notify;

interface SmsSender
{
    /**
     * Sends one SMS. Throws on failure.
     * @param string $mobileE164 "+919876543210"
     * @param string $templateId DLT template id the text was approved under
     */
    public function send(string $mobileE164, string $message, string $templateId): void;
}
