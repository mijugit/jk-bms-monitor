<?php

declare(strict_types=1);

namespace JKBMS\Core;

/**
 * Minimal front-controller router.
 *
 * Supports GET and POST routes with optional named placeholders
 * in the URI pattern (e.g. /devices/{id}).
 */
class Router
{
    /** @var array<string, array<string, callable>> method → pattern → handler */
    private array $routes = [];

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    public function get(string $pattern, callable $handler): void
    {
        $this->routes['GET'][$pattern] = $handler;
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes['POST'][$pattern] = $handler;
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $this->normaliseUri($_SERVER['REQUEST_URI'] ?? '/');

        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $pattern => $handler) {
            $params = $this->match($pattern, $uri);

            if ($params !== null) {
                $request = new Request($params);
                $handler($request);
                return;
            }
        }

        $this->notFound();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Strip query string and collapse double slashes.
     */
    private function normaliseUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Try to match $pattern against $uri.
     *
     * Named placeholders like {id} become regex groups and are returned
     * as a keyed array. Returns null on no match.
     *
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $uri): ?array
    {
        $regex = preg_replace(
            '/\\\{(\w+)\\\}/',
            '(?P<$1>[^/]+)',
            preg_quote($pattern, '#')
        );

        if (!preg_match('#^' . $regex . '$#', $uri, $matches)) {
            return null;
        }

        return array_filter(
            $matches,
            static fn($k) => is_string($k),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function notFound(): void
    {
        http_response_code(404);
        Response::view('404');
    }
}
