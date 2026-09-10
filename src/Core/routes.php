<?php

declare(strict_types=1);

/**
 * Application route definitions.
 *
 * $router is injected by public/index.php before this file is required.
 * @var \JKBMS\Core\Router $router
 */

use JKBMS\Auth\AuthController;
use JKBMS\Dashboard\DashboardController;
use JKBMS\History\HistoryController;
use JKBMS\Ingest\IngestController;

$auth      = new AuthController();
$dashboard = new DashboardController();
$ingest    = new IngestController();
$history   = new HistoryController();

// ------------------------------------------------------------------
// Auth
// ------------------------------------------------------------------

$router->get('/login',  fn($req) => $auth->loginForm($req));
$router->post('/login', fn($req) => $auth->login($req));
$router->get('/logout', fn($req) => $auth->logout($req));

// ------------------------------------------------------------------
// Dashboard (protected — AuthController::requireAuth() inside the controller)
// ------------------------------------------------------------------

$router->get('/', fn($req) => $dashboard->index($req));

// ------------------------------------------------------------------
// History API — chart data for the time-range buttons (1h/1d/1w/1m)
// ------------------------------------------------------------------

$router->get('/api/history', fn($req) => $history->index($req));

// ------------------------------------------------------------------
// Ingest API — ESP32 bridges post readings here every 30s (FR-002)
// ------------------------------------------------------------------

$router->post('/api/ingest', fn($req) => $ingest->store($req));
