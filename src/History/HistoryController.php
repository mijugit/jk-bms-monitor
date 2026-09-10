<?php

declare(strict_types=1);

namespace JKBMS\History;

use JKBMS\Auth\AuthController;
use JKBMS\Core\Request;
use JKBMS\Core\Response;
use JKBMS\Reading\ReadingRepository;

/**
 * JSON endpoint backing the dashboard's time-range chart buttons (1h/1d/1w/1m).
 */
class HistoryController
{
    private const ALLOWED_RANGES = ['1h', '1d', '1w', '1m'];

    public function __construct(
        private readonly ReadingRepository $readings = new ReadingRepository(),
    ) {}

    public function index(Request $req): void
    {
        AuthController::requireAuth();

        $deviceId = (int) $req->query('device_id', 0);
        $range = (string) $req->query('range', '1h');

        if ($deviceId <= 0 || !in_array($range, self::ALLOWED_RANGES, true)) {
            Response::json(['error' => 'Invalid device_id or range'], 400);
            return;
        }

        Response::json(['points' => $this->readings->historyAggregated($deviceId, $range)]);
    }
}
