<?php

declare(strict_types=1);

namespace JKBMS\Battery;

/**
 * Classifies a single reading as normal / warning / critical.
 *
 * This is the PRD's one-sentence business rule (see context/foundation/prd.md
 * § Business Logic): compares SOC, voltage, temperature and current against
 * fixed safety thresholds.
 *
 * The threshold values below are placeholders — PRD § Open Questions #2
 * leaves the exact safety thresholds to be validated by the owner against
 * the real JK BMS / cell datasheet before this is trusted for anything
 * beyond the PoC. Adjust the constants once real values are confirmed.
 */
final class StatusClassifier
{
    public const STATUS_NORMAL   = 'normal';
    public const STATUS_WARNING  = 'warning';
    public const STATUS_CRITICAL = 'critical';

    private const SOC_WARNING_PERCENT  = 20.0;
    private const SOC_CRITICAL_PERCENT = 10.0;

    private const CELL_MV_WARNING_LOW   = 2900; // per-cell low-voltage warning
    private const CELL_MV_CRITICAL_LOW  = 2700; // per-cell low-voltage critical
    private const CELL_MV_WARNING_HIGH  = 3550; // per-cell high-voltage warning
    private const CELL_MV_CRITICAL_HIGH = 3650; // per-cell high-voltage critical

    private const TEMP_WARNING_C  = 45.0;
    private const TEMP_CRITICAL_C = 55.0;

    private const CURRENT_WARNING_A  = 100.0;
    private const CURRENT_CRITICAL_A = 150.0;

    /**
     * @param float|null $socPercent
     * @param int|null   $cellVoltageMinMv
     * @param int|null   $cellVoltageMaxMv
     * @param float|null $tempMaxC
     * @param float|null $currentAmps absolute value (charge or discharge)
     */
    public static function classify(
        ?float $socPercent,
        ?int $cellVoltageMinMv,
        ?int $cellVoltageMaxMv,
        ?float $tempMaxC,
        ?float $currentAmps
    ): string {
        if (
            ($socPercent !== null && $socPercent <= self::SOC_CRITICAL_PERCENT)
            || ($cellVoltageMinMv !== null && $cellVoltageMinMv <= self::CELL_MV_CRITICAL_LOW)
            || ($cellVoltageMaxMv !== null && $cellVoltageMaxMv >= self::CELL_MV_CRITICAL_HIGH)
            || ($tempMaxC !== null && $tempMaxC >= self::TEMP_CRITICAL_C)
            || ($currentAmps !== null && abs($currentAmps) >= self::CURRENT_CRITICAL_A)
        ) {
            return self::STATUS_CRITICAL;
        }

        if (
            ($socPercent !== null && $socPercent <= self::SOC_WARNING_PERCENT)
            || ($cellVoltageMinMv !== null && $cellVoltageMinMv <= self::CELL_MV_WARNING_LOW)
            || ($cellVoltageMaxMv !== null && $cellVoltageMaxMv >= self::CELL_MV_WARNING_HIGH)
            || ($tempMaxC !== null && $tempMaxC >= self::TEMP_WARNING_C)
            || ($currentAmps !== null && abs($currentAmps) >= self::CURRENT_WARNING_A)
        ) {
            return self::STATUS_WARNING;
        }

        return self::STATUS_NORMAL;
    }
}
