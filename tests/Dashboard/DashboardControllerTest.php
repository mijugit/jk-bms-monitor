<?php

declare(strict_types=1);

namespace JKBMS\Tests\Dashboard;

use JKBMS\Core\Request;
use JKBMS\Dashboard\DashboardController;
use JKBMS\Device\DeviceRepository;
use JKBMS\Reading\ReadingRepository;
use PHPUnit\Framework\TestCase;

class DashboardControllerTest extends TestCase
{
    private function renderBank(?array $latest, ?int $seenAge): string
    {
        $_SESSION['authenticated'] = true;
        $devices = $this->createMock(DeviceRepository::class);
        $readings = $this->createMock(ReadingRepository::class);
        $devices->method('all')->willReturn([['id' => 1, 'name' => '<Bank>', 'last_seen_age_seconds' => $seenAge]]);
        $readings->method('latestForDevice')->willReturn($latest);
        ob_start();
        (new DashboardController($devices, $readings))->index(new Request());
        return (string) ob_get_clean();
    }

    public function testOfflineNormalReadingNeverClaimsCurrentHealthyState(): void
    {
        $html = $this->renderBank(['status' => 'normal', 'reading_age_seconds' => 3600], 3600);
        self::assertStringContainsString('Brak połączenia — widoczne są ostatnie zapisane dane.', $html);
        self::assertStringNotContainsString('Parametry w normie', $html);
        self::assertStringContainsString('1 godz. temu', $html);
        self::assertStringContainsString('&lt;Bank&gt;', $html);
    }

    public function testOfflineCriticalReadingRetainsHistoricalAlarm(): void
    {
        $html = $this->renderBank(['status' => 'critical'], 181);
        self::assertStringContainsString('Ostatni odczyt: Stan krytyczny', $html);
    }

    public function testMissingTelemetryIsNotShownAsZeroOrIdle(): void
    {
        $html = $this->renderBank(['status' => 'unknown'], 0);
        self::assertStringContainsString('Brak danych', $html);
        self::assertStringNotContainsString('Spoczynek', $html);
        self::assertStringContainsString('— <small>W', $html);
    }

    public function testDischargePowerIsMagnitudeAndDirectionRemainsVisible(): void
    {
        $html = $this->renderBank(['status' => 'normal', 'pack_voltage' => 52.5, 'current_amps' => -10, 'soc_percent' => 75], 180);
        self::assertStringContainsString('525,0 <small>W', $html);
        self::assertStringContainsString('Rozładowanie', $html);
        self::assertStringContainsString('Połączony', $html);
    }

    public function testSpreadWarningStartsAboveOneHundredMillivolts(): void
    {
        foreach ([100 => false, 101 => true] as $spread => $warns) {
            $html = $this->renderBank(['cell_voltage_min_mv' => 3200, 'cell_voltage_max_mv' => 3200 + $spread], 0);
            self::assertSame($warns, str_contains($html, 'Ogniwa się rozchodzą'));
        }
    }

    public function testCellExtremaHighlightTiesButNotEqualCellsOrResistances(): void
    {
        $html = $this->renderBank(['raw_json' => json_encode(['cell_voltages_mv' => [3200, 3300, 3300], 'cell_resistances_ohm' => [0.01, 0.02]])], 0);
        self::assertSame(2, substr_count($html, 'class="value--high">3,300'));
        self::assertStringContainsString('class="value--low">3,200', $html);
        self::assertStringNotContainsString('class="value--high">0,020', $html);
        $equal = $this->renderBank(['raw_json' => json_encode(['cell_voltages_mv' => [3300, 3300]])], 0);
        self::assertStringNotContainsString('class="value--high">3,300', $equal);
        self::assertStringNotContainsString('class="value--low">3,300', $equal);
    }

    public function testPowerLabelIdentifiesEnergyDestination(): void
    {
        foreach ([20 => 'Moc dostarczana do banku', -20 => 'Moc pobierana z banku', 0 => 'Moc — brak przepływu'] as $current => $label) {
            self::assertStringContainsString($label, $this->renderBank(['current_amps' => $current], 0));
        }
    }
    public function testBankWithoutReadingsShowsWaitingState(): void
    {
        $html = $this->renderBank(null, null);
        self::assertStringContainsString('Oczekiwanie na pierwszy odczyt', $html);
        self::assertStringNotContainsString('<canvas', $html);
    }
}
