<?php

declare(strict_types=1);

namespace Cecureus\Http;

/**
 * Tiny router: $router->get('counsellors/{id}', [CounsellorController::class, 'show']);
 * A handler is a static method: fn(Request $request, array $params): Response.
 */
final class Router
{
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

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
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
