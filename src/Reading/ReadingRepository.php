<?php

declare(strict_types=1);

namespace JKBMS\Reading;

use JKBMS\Core\Database;

/**
 * Persists and queries battery readings (FR-003: every reading, timestamp +
 * device identifier; NFR: retained indefinitely in MVP, no aggregation).
 */
class ReadingRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function insert(int $deviceId, array $data, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO readings
                (device_id, soc_percent, pack_voltage, current_amps, temp_max_c,
                 cell_voltage_min_mv, cell_voltage_max_mv, status, raw_json)
             VALUES
                (:device_id, :soc_percent, :pack_voltage, :current_amps, :temp_max_c,
                 :cell_voltage_min_mv, :cell_voltage_max_mv, :status, :raw_json)'
        );

        $stmt->execute([
            'device_id'            => $deviceId,
            'soc_percent'          => $data['soc_percent'] ?? null,
            'pack_voltage'         => $data['pack_voltage'] ?? null,
            'current_amps'         => $data['current_amps'] ?? null,
            'temp_max_c'           => $data['temp_max_c'] ?? null,
            'cell_voltage_min_mv'  => $data['cell_voltage_min_mv'] ?? null,
            'cell_voltage_max_mv'  => $data['cell_voltage_max_mv'] ?? null,
            'status'               => $status,
            'raw_json'             => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Most recent reading for a device (current-values view — FR-005).
     *
     * @return array<string, mixed>|null
     */
    public function latestForDevice(int $deviceId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM readings WHERE device_id = :device_id
             ORDER BY recorded_at DESC LIMIT 1'
        );
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * History of one parameter for a device, most recent last (chart data — FR-006).
     *
     * @return list<array<string, mixed>>
     */
    public function historyForDevice(int $deviceId, string $column, int $limit = 500): array
    {
        $allowed = ['soc_percent', 'pack_voltage', 'current_amps', 'temp_max_c'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported history column: {$column}");
        }

        $stmt = Database::connection()->prepare(
            "SELECT recorded_at, {$column} AS value FROM readings
             WHERE device_id = :device_id
             ORDER BY recorded_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('device_id', $deviceId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll());
    }
}
