<?php

declare(strict_types=1);

namespace Cecureus\Http;

/**
 * Tiny router: $router->get('counsellors/{id}', [CounsellorController::class, 'show']);
 * A handler is a static method: fn(Request $request, array $params): Response.
 *
 * Only GET, POST, PUT and DELETE are used and accepted (the CecureUs server allows only these);
 * any other method gets 405 before routing.
 */
final class Router
{
    public const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \InvalidArgumentException("Only GET, POST, PUT and DELETE routes are allowed, got {$method}");
        }
        $parts = array_map(
            static fn (string $part): string => preg_match('/^\{(\w+)\}$/', $part, $m)
                ? '(?P<' . $m[1] . '>[^/]+)'
                : preg_quote($part, '#'),
            explode('/', trim($pattern, '/')),
        );
        $this->routes[] = ['method' => $method, 'regex' => '#^' . implode('/', $parts) . '$#', 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        if (!in_array($request->method, self::ALLOWED_METHODS, true)) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Only GET, POST, PUT and DELETE are supported.');
        }
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ($route['handler'])($request, $params);
        }
        if ($pathMatched) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'This method is not allowed here.');
        }
        throw ApiException::notFound();
    }
}
