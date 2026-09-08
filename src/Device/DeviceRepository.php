<?php

declare(strict_types=1);

namespace JKBMS\Device;

use JKBMS\Core\Database;

/**
 * A "device" is one ESP32 bridge reporting for one location/bank
 * (FR-003, FR-008: location/device identifier, multi-location-ready).
 */
class DeviceRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $deviceKey): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM devices WHERE device_key = :device_key LIMIT 1'
        );
        $stmt->execute(['device_key' => $deviceKey]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM devices ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    public function touchLastSeen(int $deviceId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE devices SET last_seen_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $deviceId]);
    }

    /**
     * A device is online if it has reported within the configured window (FR-007).
     */
    public function isOnline(?string $lastSeenAt, int $offlineAfterSeconds): bool
    {
        if ($lastSeenAt === null) {
            return false;
        }

        $lastSeen = strtotime($lastSeenAt);
        if ($lastSeen === false) {
            return false;
        }

        return (time() - $lastSeen) <= $offlineAfterSeconds;
    }
}
