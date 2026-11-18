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

        $headers = [];
        try {
            $request = Request::fromGlobals();
            /** @var Router $router */
            $router = require __DIR__ . '/routes.php';
            $response = $router->dispatch($request);
        } catch (ApiException $e) {
            $response = Response::fromException($e);
            if ($e->httpStatus === 405) {
                $headers['Allow'] = implode(', ', Router::ALLOWED_METHODS);
            }
        } catch (\Throwable $e) {
            // Details go to storage/logs/app.log only — never to the client.
            Log::error('Unhandled', $e);
            $response = Response::fail(500, 'SERVER_ERROR', 'Something went wrong on our side. Please try again shortly.');
        }
        // No CORS headers: the API is for the mobile app only, and it uses only GET, POST, PUT and DELETE
        // (a browser's OPTIONS preflight is answered with 405).
        $response->send($headers);
    }
}
