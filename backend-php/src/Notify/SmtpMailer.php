<?php

declare(strict_types=1);

namespace Cecureus\Notify;

use Cecureus\Config;

/**
 * SMTP via PHPMailer (recommended for production). Install once on the server:
 *   cd backend-php && composer install --no-dev
 * Settings: SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_SECURE (tls|ssl), MAIL_FROM, MAIL_FROM_NAME.
 */
final class SmtpMailer implements Mailer
{
    public function send(string $to, string $subject, string $text): void
    {
        $class = 'PHPMailer\\PHPMailer\\PHPMailer';
        if (!class_exists($class)) {
            throw new \RuntimeException('MAIL_DRIVER=smtp needs PHPMailer: run `composer install` in backend-php');
        }
        $mail = new $class(true);
        $mail->isSMTP();
        $mail->Host = Config::require('SMTP_HOST');
        $mail->Port = Config::int('SMTP_PORT', 587);
        $mail->SMTPAuth = true;
        $mail->Username = Config::require('SMTP_USER');
        $mail->Password = Config::require('SMTP_PASS');
        $mail->SMTPSecure = Config::get('SMTP_SECURE', 'tls');
        $mail->Timeout = 10;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(Config::require('MAIL_FROM'), Config::get('MAIL_FROM_NAME', 'CecureUs') ?? 'CecureUs');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $text;
        $mail->isHTML(false);
        $mail->send();
    }
}
