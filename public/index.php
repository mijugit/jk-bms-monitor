<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require ROOT_PATH . '/vendor/autoload.php';

// Load env from .env if present (manual implementation — no library dependency)
$envFile = ROOT_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$config = require ROOT_PATH . '/config/app.php';

// Start session
session_name($config['session']['name']);
session_start();

// Boot router
$router = new JKBMS\Core\Router();
require ROOT_PATH . '/src/Core/routes.php';
$router->dispatch();
