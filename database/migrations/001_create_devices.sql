-- Migration 001 — create devices table
-- One row per ESP32 bridge / location (FR-003, FR-008: location/device identifier).
-- Run once against jkbms_db before first deploy.

CREATE TABLE IF NOT EXISTS devices (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100)     NOT NULL,
    device_key    VARCHAR(64)      NOT NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME         NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_device_key (device_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
