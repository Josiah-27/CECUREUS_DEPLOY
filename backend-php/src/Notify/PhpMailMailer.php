<?php

declare(strict_types=1);

namespace Cecureus\Notify;

use Cecureus\Config;

/** PHP mail() — works on most cPanel hosts; SMTP is more reliable for delivery. */
final class PhpMailMailer implements Mailer
{
    public function send(string $to, string $subject, string $text): void
    {
        $from = Config::require('MAIL_FROM');
        $name = str_replace(["\r", "\n", '"'], '', Config::get('MAIL_FROM_NAME', 'CecureUs') ?? 'CecureUs');
        $headers = implode("\r\n", [
            'From: "' . $name . '" <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ]);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        if (!mail($to, $encodedSubject, $text, $headers)) {
            throw new \RuntimeException('mail() failed');
        }
    }
}
