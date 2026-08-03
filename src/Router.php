<?php
/**
 * Router — exact method + path matching. No regex, no parameters.
 *
 * Every endpoint in this API is a fixed path (docs/03 §1), so anything
 * cleverer would be unused weight.
 */

declare(strict_types=1);

namespace App;

use App\Middleware\Auth;
use App\Middleware\RateLimit;

final class Router
{
    /** @var array<string, array{handler: callable|array, auth: bool, limit: ?array}> */
    private array $routes = [];

    public function get(string $path, array|callable $handler, bool $auth = false, ?array $limit = null): void
    {
        $this->add('GET', $path, $handler, $auth, $limit);
    }

    public function post(string $path, array|callable $handler, bool $auth = false, ?array $limit = null): void
    {
        $this->add('POST', $path, $handler, $auth, $limit);
    }

    public function delete(string $path, array|callable $handler, bool $auth = false, ?array $limit = null): void
    {
        $this->add('DELETE', $path, $handler, $auth, $limit);
    }

    private function add(string $method, string $path, array|callable $handler, bool $auth, ?array $limit): void
    {
        $this->routes["$method $path"] = ['handler' => $handler, 'auth' => $auth, 'limit' => $limit];
    }

    public function dispatch(Request $request): void
    {
        $key = "{$request->method} {$request->path}";

        if (!isset($this->routes[$key])) {
            // Distinguish "wrong method" from "no such endpoint" — it makes
            // client bugs obvious instead of looking like a missing route.
            foreach (array_keys($this->routes) as $existing) {
                if (str_ends_with($existing, " {$request->path}")) {
                    throw new ApiException('METHOD_NOT_ALLOWED',
                        "{$request->method} is not allowed on this endpoint.", 405);
                }
            }
            throw new ApiException('NOT_FOUND', 'Unknown endpoint.', 404);
        }

        $route = $this->routes[$key];

        /*
         * Per-IP limits run BEFORE auth, so hammering a protected endpoint
         * with junk tokens is still throttled.
         *
         * Per-USER limits must run AFTER, because the user is only known
         * once the token has been resolved. Those endpoints are already
         * behind auth, so an unauthenticated attacker never reaches them.
         */
        $limit   = $route['limit'];
        $perUser = $limit !== null && ($limit[3] ?? false) === true;

        if ($limit !== null && !$perUser) {
            RateLimit::check($request, $limit[0], $limit[1], $limit[2]);
        }

        if ($route['auth']) {
            Auth::authenticate($request);
        }

        if ($limit !== null && $perUser) {
            RateLimit::check($request, $limit[0], $limit[1], $limit[2], true);
        }

        $handler = $route['handler'];
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $handler = [new $class(), $method];
        }

        $handler($request);
    }
}
