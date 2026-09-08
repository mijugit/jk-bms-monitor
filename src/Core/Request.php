<?php

declare(strict_types=1);

namespace JKBMS\Core;

/**
 * Thin wrapper around the current HTTP request.
 *
 * Provides typed accessors for GET/POST/JSON data, route params, and
 * common headers — without pulling in a full HTTP library.
 */
class Request
{
    /**
     * @param array<string, string> $routeParams Named route parameters extracted by the Router.
     */
    public function __construct(
        private readonly array $routeParams = []
    ) {}

    // ------------------------------------------------------------------
    // Route params  (e.g. {id} from /devices/{id})
    // ------------------------------------------------------------------

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    // ------------------------------------------------------------------
    // Query string ($_GET)
    // ------------------------------------------------------------------

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    // ------------------------------------------------------------------
    // POST body ($_POST  or  JSON body)
    // ------------------------------------------------------------------

    public function input(string $key, mixed $default = null): mixed
    {
        if (!empty($_POST)) {
            return $_POST[$key] ?? $default;
        }

        return $this->jsonBody()[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonBody(): array
    {
        static $json = null;
        if ($json === null) {
            $raw  = file_get_contents('php://input');
            $json = $raw ? (json_decode($raw, true) ?? []) : [];
        }

        return $json;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        return $_SERVER[$key] ?? $default;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    // ------------------------------------------------------------------
    // CSRF helpers (web forms only — the ingest API uses a device key instead)
    // ------------------------------------------------------------------

    public function csrfToken(): string
    {
        return (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }

    public function verifyCsrf(): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        return $expected !== '' && hash_equals($expected, $this->csrfToken());
    }
}
