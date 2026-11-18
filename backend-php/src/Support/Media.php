<?php

declare(strict_types=1);

namespace Cecureus\Support;

use Cecureus\Config;

final class Media
{
    /**
     * Image column value → absolute HTTPS URL. A bare filename ("1633080520photo.png") is joined to
     * MEDIA_BASE_URL (e.g. https://eap.cecureus.com/uploads/doctor). Plain-http URLs are dropped
     * because the app refuses them.
     */
    public static function url(?string $value, string $baseKey = 'MEDIA_BASE_URL'): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^https://#i', $value)) {
            return $value;
        }
        if (preg_match('#^[a-z]+:#i', $value)) {
            return null;
        }
        $base = rtrim((string) Config::get($baseKey, Config::get('MEDIA_BASE_URL', '')), '/');
        $path = implode('/', array_map('rawurlencode', explode('/', ltrim($value, '/'))));
        return $base === '' ? $path : $base . '/' . $path;
    }
}
