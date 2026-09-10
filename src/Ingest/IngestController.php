<?php

declare(strict_types=1);

namespace JKBMS\Ingest;

use JKBMS\Battery\StatusClassifier;
use JKBMS\Core\Request;
use JKBMS\Core\Response;
use JKBMS\Device\DeviceRepository;
use JKBMS\Reading\ReadingRepository;

/**
 * Receives readings posted by an ESP32 bridge every 30s (FR-002, FR-003).
 *
 * Auth: each device authenticates with its own device_key (header
 * X-Device-Key), not the shared UI password — this keeps the ingest
 * credential separate and revocable per device even though the web UI
 * itself has only one shared password in MVP.
 */
class IngestController
{
    /**
     * Floor on how often one device's readings are actually stored, regardless
     * of how often it POSTs. FR-002 specifies a 30s cadence, but the JK BMS
     * auto-streams a reading roughly once a second once its BLE session is
     * unlocked (confirmed on real hardware) — the firmware's own 30s request
     * timer only throttles when *it* asks for a reading, not how often the
     * BMS pushes one or how often we forward it here. Until the firmware is
     * fixed at the source (deferred — no device access right now), this is
     * the backstop that keeps the table from filling at ~1 row/s. Slightly
     * under 30 to tolerate jitter without ever under-shooting the cadence.
     */
    private const MIN_STORE_INTERVAL_SECONDS = 25;

    public function __construct(
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly ReadingRepository $readings = new ReadingRepository(),
    ) {}

    public function store(Request $req): void
    {
        $deviceKey = (string) $req->header('X-Device-Key', '');
        if ($deviceKey === '') {
            Response::json(['error' => 'Missing X-Device-Key header'], 401);
            return;
        }

        $device = $this->devices->findByKey($deviceKey);
        if ($device === null) {
            Response::json(['error' => 'Unknown device key'], 401);
            return;
        }

        $deviceId = (int) $device['id'];
        $this->devices->touchLastSeen($deviceId); // device is alive/reachable either way

        $latest = $this->readings->latestForDevice($deviceId);
        if ($latest !== null) {
            $secondsSinceLast = time() - strtotime((string) $latest['recorded_at']);
            if ($secondsSinceLast < self::MIN_STORE_INTERVAL_SECONDS) {
                Response::json([
                    'status' => $latest['status'],
                    'stored' => false,
                    'reason' => 'throttled',
                    'retry_after_seconds' => self::MIN_STORE_INTERVAL_SECONDS - $secondsSinceLast,
                ], 200);
                return;
            }
        }

        $body = $req->jsonBody();

        $socPercent        = isset($body['soc_percent']) ? (float) $body['soc_percent'] : null;
        $packVoltage        = isset($body['pack_voltage']) ? (float) $body['pack_voltage'] : null;
        $currentAmps        = isset($body['current_amps']) ? (float) $body['current_amps'] : null;
        $tempMaxC           = isset($body['temp_max_c']) ? (float) $body['temp_max_c'] : null;
        $cellVoltageMinMv   = isset($body['cell_voltage_min_mv']) ? (int) $body['cell_voltage_min_mv'] : null;
        $cellVoltageMaxMv   = isset($body['cell_voltage_max_mv']) ? (int) $body['cell_voltage_max_mv'] : null;

        $status = StatusClassifier::classify(
            $socPercent,
            $cellVoltageMinMv,
            $cellVoltageMaxMv,
            $tempMaxC,
            $currentAmps
        );

        $this->readings->insert($deviceId, $body, $status);

        Response::json(['status' => $status, 'stored' => true], 201);
    }
}
