<?php

declare(strict_types=1);

namespace JKBMS\Core;

/**
 * Minimal response helpers.
 *
 * All methods terminate execution unless $exit = false is passed.
 */
class Response
{
    // ------------------------------------------------------------------
    // Redirects
    // ------------------------------------------------------------------

    public static function redirect(string $url, int $status = 302, bool $exit = true): void
    {
        http_response_code($status);
        header('Location: ' . $url);

        if ($exit) {
            exit;
        }
    }

    // ------------------------------------------------------------------
    // JSON
    // ------------------------------------------------------------------

    public static function json(mixed $data, int $status = 200, bool $exit = true): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($exit) {
            exit;
        }
    }

    // ------------------------------------------------------------------
    // HTML templates
    // ------------------------------------------------------------------

    /**
     * Render a template file inside the main layout.
     *
     * @param string               $template  Relative path inside /templates (without .php)
     * @param array<string, mixed> $data      Variables extracted into the template scope
     */
    public static function view(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);

        $templatePath = dirname(__DIR__, 2) . '/templates/' . $template . '.php';

        if (!file_exists($templatePath)) {
            http_response_code(500);
            echo 'Template not found: ' . htmlspecialchars($template);
            exit;
        }

        // Buffer content block, then inject into layout.
        ob_start();
        require $templatePath;
        $content = ob_get_clean();

        require dirname(__DIR__, 2) . '/templates/layout.php';
    }

    // ------------------------------------------------------------------
    // Abort
    // ------------------------------------------------------------------

    public static function abort(int $status, string $message = ''): never
    {
        http_response_code($status);
        echo htmlspecialchars($message);
        exit;
    }
}
