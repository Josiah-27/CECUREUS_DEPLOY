<?php

declare(strict_types=1);

use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Http\Router;

/** A request with just a method and a path, like Request::fromGlobals() produces. */
function routeRequest(string $method, string $path): Request
{
    return new Request($method, $path, [], [], '', '127.0.0.1');
}

test('a matching route runs its handler with the captured params', function (): void {
    $router = new Router();
    $router->get('counsellors/{id}', static fn (Request $r, array $p): Response => Response::ok($p));
    $response = $router->dispatch(routeRequest('GET', 'counsellors/7'));
    check($response->status === 200 && ($response->body['data']['id'] ?? '') === '7', 'params not captured');
});

test('several params are captured and the literal parts must match', function (): void {
    $router = new Router();
    $router->get('a/{x}/b/{y}', static fn (Request $r, array $p): Response => Response::ok($p));
    $response = $router->dispatch(routeRequest('GET', 'a/one/b/two'));
    check(($response->body['data']['x'] ?? '') === 'one' && ($response->body['data']['y'] ?? '') === 'two', 'params wrong');
    throwsApi(404, 'NOT_FOUND', fn () => $router->dispatch(routeRequest('GET', 'a/one/c/two')));
});

test('a known path with the wrong method answers 405, not 404', function (): void {
    $router = new Router();
    $router->get('health', static fn (): Response => Response::ok(['ok' => true]));
    throwsApi(405, 'METHOD_NOT_ALLOWED', fn () => $router->dispatch(routeRequest('POST', 'health')));
});

test('unknown paths answer 404', function (): void {
    $router = new Router();
    $router->get('health', static fn (): Response => Response::ok(['ok' => true]));
    throwsApi(404, 'NOT_FOUND', fn () => $router->dispatch(routeRequest('GET', 'nope')));
    throwsApi(404, 'NOT_FOUND', fn () => $router->dispatch(routeRequest('GET', 'health/extra')));
});

test('methods outside GET, POST, PUT and DELETE are rejected before routing', function (): void {
    $router = new Router();
    throwsApi(405, 'METHOD_NOT_ALLOWED', fn () => $router->dispatch(routeRequest('PATCH', 'nope')));
    throwsApi(405, 'METHOD_NOT_ALLOWED', fn () => $router->dispatch(routeRequest('OPTIONS', 'health')));
});

test('registering a route with another method is refused', function (): void {
    $router = new Router();
    try {
        $router->add('PATCH', 'x', static fn (): Response => Response::ok(null));
        throw new RuntimeException('PATCH route accepted');
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'PATCH'), 'message does not name the method');
    }
});
