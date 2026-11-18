<?php

declare(strict_types=1);

namespace Cecureus;

/**
 * Reads settings from backend-php/.env (KEY=VALUE lines) and real environment variables.
 * Real environment variables win, so a hosting panel can override the file.
 * Secrets (DB password, SMS/SMTP credentials, APP_SECRET) live ONLY here — never in the app.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        $value = self::$values[$key] ?? '';
        return $value !== '' ? $value : $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException("Missing required setting {$key} in backend-php/.env");
        }
        return $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        $value = self::get($key);
        return $value === null ? [] : array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    /** development | staging | production. Log-only SMS/email drivers are refused in production. */
    public static function env(): string
    {
        return self::get('APP_ENV', 'production') ?? 'production';
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    /** Server-side secret used to hash OTP codes. At least 32 characters. */
    public static function secret(): string
    {
        $secret = self::require('APP_SECRET');
        if (strlen($secret) < 32 || str_starts_with($secret, 'change-me')) {
            throw new \RuntimeException('APP_SECRET must be a random value of at least 32 characters');
        }
        return $secret;
    }
}
