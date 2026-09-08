-- Migration 002 — create readings table
-- One row per reading received from a device (FR-003). Retained indefinitely
-- in MVP (NFR: no automatic deletion/aggregation).

CREATE TABLE IF NOT EXISTS readings (
    id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    device_id             INT UNSIGNED     NOT NULL,
    recorded_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    soc_percent           DECIMAL(5,2)     NULL,
    pack_voltage          DECIMAL(6,2)     NULL,
    current_amps          DECIMAL(7,2)     NULL,
    temp_max_c            DECIMAL(5,2)     NULL,
    cell_voltage_min_mv   INT UNSIGNED     NULL,
    cell_voltage_max_mv   INT UNSIGNED     NULL,

    -- Business Logic classification (see src/Battery/StatusClassifier.php)
    status                ENUM('normal', 'warning', 'critical') NOT NULL DEFAULT 'normal',

    -- Full parameter dump as received from the device (FR-001: read everything available).
    raw_json              JSON             NULL,

    PRIMARY KEY (id),
    KEY idx_readings_device_recorded (device_id, recorded_at),
    CONSTRAINT fk_readings_device FOREIGN KEY (device_id) REFERENCES devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
