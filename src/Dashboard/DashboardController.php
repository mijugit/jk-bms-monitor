<?php

declare(strict_types=1);

namespace JKBMS\Dashboard;

use JKBMS\Auth\AuthController;
use JKBMS\Core\Request;
use JKBMS\Core\Response;
use JKBMS\Device\DeviceRepository;
use JKBMS\Reading\ReadingRepository;

/**
 * Current values + history chart for every location (FR-005, FR-006, FR-007).
 */
class DashboardController
{
    public function __construct(
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly ReadingRepository $readings = new ReadingRepository(),
    ) {}

    public function index(Request $req): void
    {
        AuthController::requireAuth();

        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $offlineAfter = (int) $config['device_offline_after_seconds'];

        $rows = [];
        foreach ($this->devices->all() as $device) {
            $latest = $this->readings->latestForDevice((int) $device['id']);

            $rows[] = [
                'device'  => $device,
                'latest'  => $latest,
                'online'  => $this->devices->isOnline($device['last_seen_at'] ?? null, $offlineAfter),
                'history' => $this->readings->historyForDevice((int) $device['id'], 'soc_percent'),
            ];
        }

        Response::view('dashboard', ['rows' => $rows]);
    }
}
