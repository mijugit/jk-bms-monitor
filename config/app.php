<?php

declare(strict_types=1);

return [
    'name'     => 'JK BMS Monitor',
    'env'      => $_ENV['APP_ENV'] ?? 'production',
    'debug'    => ($_ENV['APP_ENV'] ?? 'production') === 'development',
    'base_url' => $_ENV['APP_URL'] ?? 'http://localhost',

    // Single shared password gating the whole web app (Access Control — no per-user accounts in MVP).
    'app_password' => $_ENV['JKBMS_PASS'] ?? '',

    'db' => [
        'driver'   => 'mysql',
        'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port'     => $_ENV['DB_PORT'] ?? '3306',
        'name'     => $_ENV['DB_NAME'] ?? 'jkbms_db',
        'user'     => $_ENV['DB_USER'] ?? 'root',
        'password' => $_ENV['DB_PASS'] ?? '',
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'name'     => 'jkbms_session',
        'lifetime' => 7200, // seconds
    ],

    // A device is "offline" if no reading has arrived within this window (FR-007).
    'device_offline_after_seconds' => 180,
];
