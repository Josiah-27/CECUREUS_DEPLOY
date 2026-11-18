<?php

declare(strict_types=1);

namespace Cecureus;

use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Http\Router;
use Cecureus\Support\Log;

final class App
{
    public static function run(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        // Turn PHP warnings into exceptions so nothing half-works silently.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $corsHeaders = [];
        try {
            $request = Request::fromGlobals();
            $corsHeaders = self::corsHeaders($request);
            if ($request->method === 'OPTIONS') {
                $response = Response::noContent();
            } else {
                /** @var Router $router */
                $router = require __DIR__ . '/routes.php';
                $response = $router->dispatch($request);
            }
        } catch (ApiException $e) {
            $response = Response::fromException($e);
        } catch (\Throwable $e) {
            // Details go to storage/logs/app.log only — never to the client.
            Log::error('Unhandled', $e);
            $response = Response::fail(500, 'SERVER_ERROR', 'Something went wrong on our side. Please try again shortly.');
        }
        $response->send($corsHeaders);
    }

    /**
     * The Android app does not need CORS. Only the web preview does — list its origins in
     * CORS_ALLOWED_ORIGINS (comma separated). Never use "*" with Authorization headers.
     * @return array<string, string>
     */
    private static function corsHeaders(Request $request): array
    {
        $origin = $request->header('origin');
        if ($origin === null || !in_array($origin, Config::list('CORS_ALLOWED_ORIGINS'), true)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, Idempotency-Key',
            'Access-Control-Expose-Headers' => 'Retry-After',
            'Access-Control-Max-Age' => '600',
        ];
    }
}
