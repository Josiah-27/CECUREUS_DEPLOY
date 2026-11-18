<?php

declare(strict_types=1);

namespace Cecureus\Notify;

use Cecureus\Config;

/**
 * Picks the SMS / email driver from .env:
 *   SMS_DRIVER  = smsintegra | log
 *   MAIL_DRIVER = smtp | mail | log
 * "log" only writes to storage/logs/messages.log and is refused when APP_ENV=production,
 * so a misconfigured production server fails loudly instead of silently not sending codes.
 */
final class Channels
{
    public static function sms(): SmsSender
    {
        return match (self::driver('SMS_DRIVER')) {
            'smsintegra' => new SmsIntegraSender(),
            'log' => new LogSmsSender(),
            default => throw new \RuntimeException('Unknown SMS_DRIVER'),
        };
    }

    public static function mail(): Mailer
    {
        return match (self::driver('MAIL_DRIVER')) {
            'smtp' => new SmtpMailer(),
            'mail' => new PhpMailMailer(),
            'log' => new LogMailer(),
            default => throw new \RuntimeException('Unknown MAIL_DRIVER'),
        };
    }

    private static function driver(string $key): string
    {
        $driver = strtolower(Config::get($key, 'log') ?? 'log');
        if ($driver === 'log' && Config::isProduction()) {
            throw new \RuntimeException("{$key}=log is not allowed when APP_ENV=production");
        }
        return $driver;
    }
}
