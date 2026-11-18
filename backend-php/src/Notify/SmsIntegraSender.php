<?php

declare(strict_types=1);

namespace Cecureus\Notify;

use Cecureus\Config;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * SMS Integra HTTP API, called from the SERVER over HTTPS. The uid/pwd/sid/entityid stay in
 * backend-php/.env and never reach the app. The text must match the DLT-approved template exactly.
 *
 * The gateway answers HTTP 200 even when it refuses a message (e.g. "91 - Invalid Template ID"),
 * so the reply text is checked too.
 */
final class SmsIntegraSender implements SmsSender
{
    private const REJECTED = '/invalid|error|fail|denied|insufficient|expired|blocked|unauthori[sz]ed|not\s+(valid|allowed|found|enough)|please provide/i';

    public function send(string $mobileE164, string $message, string $templateId): void
    {
        $endpoint = Config::get('SMSINTEGRA_URL', 'https://www.smsintegra.com/api/smsapi.aspx') ?? '';
        if (!str_starts_with($endpoint, 'https://')) {
            throw new \RuntimeException('SMSINTEGRA_URL must use https');
        }
        if ($templateId === '') {
            throw new \RuntimeException('SMS template id is not configured');
        }
        $query = http_build_query([
            'uid' => Config::require('SMSINTEGRA_UID'),
            'pwd' => Config::require('SMSINTEGRA_PWD'),
            'mobile' => '91' . Text::mobile10($mobileE164),
            'msg' => $message,
            'sid' => Config::require('SMSINTEGRA_SID'),
            'type' => '0',
            'dtTimeNow' => gmdate('Y-m-d H:i:s', time() + Time::IST_OFFSET),
            'entityid' => Config::require('SMSINTEGRA_ENTITY_ID'),
            'tempid' => $templateId,
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($endpoint . '?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $reply = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Never include the URL in errors/logs: it contains the password.
        if ($reply === false) {
            throw new \RuntimeException('SMS gateway unreachable: ' . $error);
        }
        $reply = trim(substr((string) $reply, 0, 200));
        if ($status !== 200 || preg_match(self::REJECTED, $reply)) {
            throw new \RuntimeException("SMS gateway refused the message (HTTP {$status}): {$reply}");
        }
    }
}
