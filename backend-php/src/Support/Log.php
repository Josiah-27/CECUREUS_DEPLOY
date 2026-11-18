<?php

declare(strict_types=1);

namespace Cecureus\Support;

use Cecureus\Config;

use const Cecureus\ROOT;

/** Append-only log file. Never log passwords, OTP codes (outside the dev message log), tokens or SMS credentials. */
final class Log
{
    public static function error(string $message, ?\Throwable $e = null): void
    {
        if ($e !== null) {
            $message .= ' | ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        }
        self::write('app.log', 'ERROR ' . $message);
    }

    public static function info(string $message): void
    {
        self::write('app.log', 'INFO ' . $message);
    }

    /**
     * Development/staging only: SMS and emails that the "log" drivers would have sent, so you can
     * test sign-up without a gateway. bin/smoke-test.php reads OTP codes from here.
     */
    public static function message(string $line): void
    {
        self::write('messages.log', $line);
    }

    public static function dir(): string
    {
        $dir = Config::get('LOG_DIR', ROOT . '/storage/logs') ?? (ROOT . '/storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    private static function write(string $file, string $line): void
    {
        $entry = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . str_replace(["\r", "\n"], ' ', $line) . PHP_EOL;
        if (@file_put_contents(self::dir() . '/' . $file, $entry, FILE_APPEND | LOCK_EX) === false) {
            error_log('cecureus-api: ' . $entry);
        }
    }
}
