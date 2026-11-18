<?php

declare(strict_types=1);

namespace Cecureus\Support;

final class Text
{
    /**
     * Free text typed by a person: removes control characters and < > (the admin panel renders
     * these columns as HTML), collapses whitespace and cuts to $max characters.
     */
    public static function clean(mixed $value, int $max): string
    {
        $s = is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
        $s = preg_replace('/[\x00-\x1F\x7F<>]/u', '', $s) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max);
    }

    public static function orNull(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : $s;
    }

    public static function isEmail(string $value): bool
    {
        return strlen($value) <= 250 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Normalises Indian mobile formats seen in employee_list.emp_mobile ("9876543210",
     * "91-9876543210", "09876543210", "98765 43210", "+919876543210") to E.164 "+919876543210".
     */
    public static function normalizeMobile(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? '')) ?? '';
        return preg_match('/^(?:0|91|091|0091)?([6-9]\d{9})$/', $digits, $m) ? '+91' . $m[1] : null;
    }

    /** "+919876543210" → "9876543210" (how existing rows store emp_mobile). */
    public static function mobile10(string $e164): string
    {
        return substr($e164, -10);
    }

    /** "+919876543210" → "+91******3210" for logs. */
    public static function maskMobile(string $e164): string
    {
        return substr($e164, 0, 3) . '******' . substr($e164, -4);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return substr($local, 0, 1) . '***@' . $domain;
    }

    /** Escapes % _ and \ for use inside a LIKE ? parameter. */
    public static function likeEscape(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /** HTML (blog/description blobs) → plain text. */
    public static function plain(?string $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** "A, B, A" → ["A", "B"]. */
    public static function csvList(?string $value): array
    {
        $items = array_filter(array_map('trim', explode(',', (string) $value)), 'strlen');
        return array_values(array_unique($items));
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** First integer in a string ("28 Years" → 28). */
    public static function firstInt(?string $value): ?int
    {
        return preg_match('/(\d+)/', (string) $value, $m) ? (int) $m[1] : null;
    }

    public static function isId(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9]\d{0,9}$/', (string) $value) === 1;
    }
}
