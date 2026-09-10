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
     * Time-bucketed SOC% + current history for a device, oldest first (chart
     * data — FR-006). The device streams roughly one reading per second, so
     * a raw week/month of rows would be hundreds of thousands of points —
     * far too many to ship to the browser or plot usefully. Each supported
     * range buckets readings server-side (SQL AVG per bucket) down to a
     * chart-friendly point count instead.
     *
     * @return list<array{t: string, soc_percent: ?float, current_amps: ?float}>
     */
    public function historyAggregated(int $deviceId, string $range): array
    {
        // range => [bucket size in seconds, lookback window for the SQL interval]
        $ranges = [
            '1h' => [20,    '1 HOUR'],
            '1d' => [300,   '1 DAY'],
            '1w' => [3600,  '7 DAY'],
            '1m' => [21600, '30 DAY'],
        ];
        if (!isset($ranges[$range])) {
            throw new \InvalidArgumentException("Unsupported history range: {$range}");
        }
        [$bucketSeconds, $interval] = $ranges[$range];

        $stmt = Database::connection()->prepare(
            "SELECT
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / :bucket) * :bucket) AS t,
                AVG(soc_percent) AS soc_percent,
                AVG(current_amps) AS current_amps
             FROM readings
             WHERE device_id = :device_id
               AND recorded_at >= DATE_SUB(NOW(), INTERVAL {$interval})
             GROUP BY t
             ORDER BY t ASC"
        );
        $stmt->bindValue('bucket', $bucketSeconds, \PDO::PARAM_INT);
        $stmt->bindValue('device_id', $deviceId, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
