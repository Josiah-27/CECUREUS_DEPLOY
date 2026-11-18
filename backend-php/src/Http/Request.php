<?php

declare(strict_types=1);

namespace Cecureus\Http;

use Cecureus\Config;

final class Request
{
    private const MAX_BODY_BYTES = 65536;

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers lower-case header names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        private readonly string $rawBody,
        private readonly string $remoteAddr,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        // Apache with PHP-FPM/CGI hides Authorization unless .htaccess copies it (see public/.htaccess).
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth === null && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $auth = $value;
                }
            }
        }
        if (is_string($auth) && $auth !== '') {
            $headers['authorization'] = $auth;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $uriPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $basePath = rtrim((string) Config::get('BASE_PATH', '/api/mobile/v1'), '/');
        if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
            $uriPath = substr($uriPath, strlen($basePath));
        } else {
            // Installed in a sub-folder that differs from BASE_PATH: strip the folder of index.php.
            $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
            if ($scriptDir !== '' && str_starts_with($uriPath, $scriptDir)) {
                $uriPath = substr($uriPath, strlen($scriptDir));
            }
        }
        $path = trim(rawurldecode($uriPath), '/');
        if ($path === 'index.php') {
            $path = '';
        }

        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($v)) {
                $query[(string) $k] = $v;
            }
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'The request is too large.');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'The request is too large.');
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $query,
            $headers,
            $raw,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;
        return $value === null || $value === '' ? null : $value;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null || !preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,200})$/', trim($auth), $m)) {
            return null;
        }
        return $m[1];
    }

    /** The JSON body as an array ({} when empty). Rejects non-JSON bodies. */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (trim($this->rawBody) === '') {
            return $this->json = [];
        }
        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'The request body is not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw new ApiException(400, 'INVALID_JSON', 'The request body must be a JSON object.');
        }
        return $this->json = $decoded;
    }

    /** Client IP. Behind a reverse proxy/CDN set TRUST_PROXY=true to use X-Forwarded-For. */
    public function ip(): string
    {
        if (Config::bool('TRUST_PROXY', false)) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null) {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        return $this->remoteAddr;
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent') ?? '', 0, 255);
    }
}
