<?php

declare(strict_types=1);

namespace JKBMS\Tests\Battery;

use JKBMS\Battery\StatusClassifier;
use PHPUnit\Framework\TestCase;

class StatusClassifierTest extends TestCase
{
    public function test_normal_reading_is_classified_normal(): void
    {
        $status = StatusClassifier::classify(
            socPercent: 80.0,
            cellVoltageMinMv: 3300,
            cellVoltageMaxMv: 3320,
            tempMaxC: 25.0,
            currentAmps: 10.0
        );

        $this->assertSame(StatusClassifier::STATUS_NORMAL, $status);
    }

    public function test_low_soc_is_classified_warning(): void
    {
        $status = StatusClassifier::classify(
            socPercent: 15.0,
            cellVoltageMinMv: 3300,
            cellVoltageMaxMv: 3320,
            tempMaxC: 25.0,
            currentAmps: 10.0
        );

        $this->assertSame(StatusClassifier::STATUS_WARNING, $status);
    }

    public function test_very_low_soc_is_classified_critical(): void
    {
        $status = StatusClassifier::classify(
            socPercent: 5.0,
            cellVoltageMinMv: 3300,
            cellVoltageMaxMv: 3320,
            tempMaxC: 25.0,
            currentAmps: 10.0
        );

        $this->assertSame(StatusClassifier::STATUS_CRITICAL, $status);
    }

    public function test_high_temperature_is_classified_critical(): void
    {
        $status = StatusClassifier::classify(
            socPercent: 80.0,
            cellVoltageMinMv: 3300,
            cellVoltageMaxMv: 3320,
            tempMaxC: 60.0,
            currentAmps: 10.0
        );

        $this->assertSame(StatusClassifier::STATUS_CRITICAL, $status);
    }

    public function test_missing_values_are_ignored_not_treated_as_failures(): void
    {
        $status = StatusClassifier::classify(
            socPercent: null,
            cellVoltageMinMv: null,
            cellVoltageMaxMv: null,
            tempMaxC: null,
            currentAmps: null
        );

        $this->assertSame(StatusClassifier::STATUS_NORMAL, $status);
    }
}
