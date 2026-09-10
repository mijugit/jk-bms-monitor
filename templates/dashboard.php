<h1>Banki energii</h1>

<?php if (empty($rows)): ?>
<div class="empty-state">
    <video class="empty-state__video" src="/videos/battery-charging.webm" autoplay muted loop playsinline></video>
    <p>Brak zarejestrowanych urządzeń. Dodaj wiersz w tabeli <code>devices</code> (nazwa lokalizacji + <code>device_key</code>) i skonfiguruj nim firmware ESP32.</p>
</div>
<?php endif; ?>

<div class="device-grid">
<?php foreach ($rows as $row): ?>
    <?php
        $device  = $row['device'];
        $latest  = $row['latest'];
        $online  = $row['online'];
        $status  = $latest['status'] ?? null;
        $raw     = $latest && !empty($latest['raw_json']) ? (json_decode((string) $latest['raw_json'], true) ?? []) : [];

        $statusText = [
            'normal'   => 'Bank działa poprawnie.',
            'warning'  => 'Ostrzeżenie — parametr zbliża się do progu bezpieczeństwa.',
            'critical' => 'Stan krytyczny — sprawdź bank natychmiast.',
        ][$status] ?? 'Brak danych o statusie.';

        $soc         = $latest['soc_percent'] ?? null;
        $socPercent  = $soc !== null ? max(0, min(100, (float) $soc)) : 0;
        $voltage     = $latest['pack_voltage'] ?? null;
        $current     = $latest['current_amps'] ?? null;
        $cellMinMv   = $latest['cell_voltage_min_mv'] ?? null;
        $cellMaxMv   = $latest['cell_voltage_max_mv'] ?? null;
        $voltDiffV   = ($cellMinMv !== null && $cellMaxMv !== null) ? ($cellMaxMv - $cellMinMv) / 1000 : null;
        $powerW      = ($voltage !== null && $current !== null) ? abs($voltage * $current) : null; // magnitude — direction is shown via the Status label

        $chargeMosfetOn    = $raw['charge_mosfet_on'] ?? null;
        $dischargeMosfetOn = $raw['discharge_mosfet_on'] ?? null;
        $balancerStatus    = $raw['balancer_status'] ?? null; // 0=off, 1=charging balance, 2=discharging balance
        $balanceCurrentA   = $raw['balance_current_amps'] ?? null;
        $balancerOn        = $balancerStatus !== null && (int) $balancerStatus !== 0;
        $balancerLabel     = ['Wył.', 'Ład.', 'Rozł.'][(int) ($balancerStatus ?? 0)] ?? 'Wył.';

        $currentStatusLabel = 'Bezczynny';
        if ($current !== null) {
            if ($current > 0.05) $currentStatusLabel = 'Ładowanie';
            elseif ($current < -0.05) $currentStatusLabel = 'Rozładowanie';
        }

        $cellVoltages   = $raw['cell_voltages_mv'] ?? [];
        $cellResistances = $raw['cell_resistances_ohm'] ?? [];

        // Speedometer-style SOC gauge: 270° arc (90° gap centered at the bottom),
        // built with the stroke-dasharray trick — dasharray = "<visible arc> <rest>",
        // then the whole circle is rotated so the arc starts at bottom-left (135°,
        // measured clockwise from 3-o'clock, SVG's native circle start point).
        $gaugeCx = 70;
        $gaugeCy = 70;
        $gaugeRadius = 52;
        $gaugeInnerRadius = 43;
        $gaugeSweepDeg = 270;
        $gaugeStartDeg = 135;
        $gaugeCircumference = 2 * M_PI * $gaugeRadius;
        $gaugeInnerCircumference = 2 * M_PI * $gaugeInnerRadius;
        $gaugeArcLen = $gaugeCircumference * ($gaugeSweepDeg / 360);
        $gaugeInnerArcLen = $gaugeInnerCircumference * ($gaugeSweepDeg / 360);
        $gaugeFillLen = $gaugeArcLen * ($socPercent / 100);
        $gaugeInnerFillLen = $gaugeInnerArcLen * ($socPercent / 100);

        $jkGaugePoint = static function (float $cx, float $cy, float $r, float $angleDeg): array {
            $rad = deg2rad($angleDeg);
            return [round($cx + $r * cos($rad), 2), round($cy + $r * sin($rad), 2)];
        };

        $gaugeTicks = [];
        $gaugeTickCount = 24;
        for ($i = 0; $i <= $gaugeTickCount; $i++) {
            $gaugeTicks[] = $jkGaugePoint($gaugeCx, $gaugeCy, $gaugeRadius - 15, $gaugeStartDeg + $gaugeSweepDeg * $i / $gaugeTickCount);
        }
        [$gaugeLabel0X, $gaugeLabel0Y]     = $jkGaugePoint($gaugeCx, $gaugeCy, $gaugeRadius + 13, $gaugeStartDeg);
        [$gaugeLabel100X, $gaugeLabel100Y] = $jkGaugePoint($gaugeCx, $gaugeCy, $gaugeRadius + 13, $gaugeStartDeg + $gaugeSweepDeg);
    ?>
    <section class="device-card device-card--<?= htmlspecialchars($status ?? 'unknown') ?>">
        <header class="device-card__header">
            <h2><?= htmlspecialchars((string) $device['name']) ?></h2>
            <span class="badge badge--<?= $online ? 'online' : 'offline' ?>"><?= $online ? 'online' : 'offline' ?></span>
        </header>

        <?php if ($latest === null): ?>
        <p>Brak jeszcze żadnego odczytu z tego urządzenia.</p>
        <?php else: ?>

        <div class="mosfet-row">
            <span class="mosfet-pill <?= $chargeMosfetOn ? 'is-on' : '' ?>">Chg <i></i> <?= $chargeMosfetOn ? 'ON' : 'OFF' ?></span>
            <span class="mosfet-pill <?= $dischargeMosfetOn ? 'is-on' : '' ?>">Dsg <i></i> <?= $dischargeMosfetOn ? 'ON' : 'OFF' ?></span>
            <span class="mosfet-pill <?= $balancerOn ? 'is-on' : '' ?>">Bal. <i></i> <?= $balancerLabel === 'Wył.' ? 'OFF' : $balancerLabel ?></span>
        </div>

        <div class="gauge">
            <svg viewBox="0 0 140 140">
                <defs>
                    <linearGradient id="gaugeGradient-<?= (int) $device['id'] ?>" x1="0" y1="70" x2="140" y2="70" gradientUnits="userSpaceOnUse">
                        <stop offset="0%"   stop-color="#3ddc84" />
                        <stop offset="50%"  stop-color="#2fd0c9" />
                        <stop offset="100%" stop-color="#4fb8ff" />
                    </linearGradient>
                </defs>

                <!-- tick/scale dots -->
                <?php foreach ($gaugeTicks as [$tx, $ty]): ?>
                <circle class="gauge__tick" cx="<?= $tx ?>" cy="<?= $ty ?>" r="1.3" />
                <?php endforeach; ?>

                <!-- background track (full 270° range, dim) -->
                <circle class="gauge__track" cx="<?= $gaugeCx ?>" cy="<?= $gaugeCy ?>" r="<?= $gaugeRadius ?>"
                        stroke-dasharray="<?= round($gaugeArcLen, 2) ?> <?= round($gaugeCircumference - $gaugeArcLen, 2) ?>"
                        transform="rotate(<?= $gaugeStartDeg ?> <?= $gaugeCx ?> <?= $gaugeCy ?>)" />

                <!-- filled portion (outer, thick, gradient or status color) -->
                <circle class="gauge__fill gauge__fill--<?= htmlspecialchars($status ?? 'unknown') ?>"
                        cx="<?= $gaugeCx ?>" cy="<?= $gaugeCy ?>" r="<?= $gaugeRadius ?>"
                        stroke="<?= $status === 'normal' ? 'url(#gaugeGradient-' . (int) $device['id'] . ')' : 'currentColor' ?>"
                        stroke-dasharray="<?= round($gaugeFillLen, 2) ?> <?= round($gaugeCircumference - $gaugeFillLen, 2) ?>"
                        transform="rotate(<?= $gaugeStartDeg ?> <?= $gaugeCx ?> <?= $gaugeCy ?>)" />

                <!-- inner accent ring, thinner, same fill fraction -->
                <circle class="gauge__fill-inner gauge__fill-inner--<?= htmlspecialchars($status ?? 'unknown') ?>"
                        cx="<?= $gaugeCx ?>" cy="<?= $gaugeCy ?>" r="<?= $gaugeInnerRadius ?>"
                        stroke-dasharray="<?= round($gaugeInnerFillLen, 2) ?> <?= round($gaugeInnerCircumference - $gaugeInnerFillLen, 2) ?>"
                        transform="rotate(<?= $gaugeStartDeg ?> <?= $gaugeCx ?> <?= $gaugeCy ?>)" />

                <text class="gauge__scale-label" x="<?= $gaugeLabel0X ?>" y="<?= $gaugeLabel0Y ?>" text-anchor="middle" dominant-baseline="middle">0</text>
                <text class="gauge__scale-label" x="<?= $gaugeLabel100X ?>" y="<?= $gaugeLabel100Y ?>" text-anchor="middle" dominant-baseline="middle">100</text>
            </svg>
            <div class="gauge__label">
                <span class="gauge__percent gauge__percent--<?= htmlspecialchars($status ?? 'unknown') ?>"><?= $soc !== null ? htmlspecialchars((string) round((float) $soc)) : '—' ?><small>%</small></span>
            </div>
        </div>

        <div class="gauge__pills">
            <span class="pill pill--<?= htmlspecialchars($status ?? 'unknown') ?>"><?= $voltage !== null ? htmlspecialchars((string) $voltage) : '—' ?> V</span>
            <span class="pill pill--<?= htmlspecialchars($status ?? 'unknown') ?>"><?= $current !== null ? htmlspecialchars((string) $current) : '—' ?> A</span>
        </div>

        <p class="status-message status-message--<?= htmlspecialchars($status ?? 'unknown') ?>"><?= htmlspecialchars($statusText) ?></p>

        <div class="stat-grid">
            <div class="stat"><span class="stat__value stat__value--high"><?= $cellMaxMv !== null ? htmlspecialchars(number_format($cellMaxMv / 1000, 3)) : '—' ?></span><span class="stat__label">High Cell (V)</span></div>
            <div class="stat"><span class="stat__value stat__value--low"><?= $cellMinMv !== null ? htmlspecialchars(number_format($cellMinMv / 1000, 3)) : '—' ?></span><span class="stat__label">Low Cell (V)</span></div>
            <div class="stat"><span class="stat__value"><?= $voltDiffV !== null ? htmlspecialchars(number_format($voltDiffV, 3)) : '—' ?></span><span class="stat__label">Volt-Diff (V)</span></div>
            <div class="stat"><span class="stat__value"><?= $balanceCurrentA !== null ? htmlspecialchars(number_format((float) $balanceCurrentA, 3)) : '—' ?></span><span class="stat__label">Bal-Curr (A)</span></div>
        </div>

        <div class="stat-grid">
            <div class="stat"><span class="stat__value"><?= isset($raw['full_capacity_ah']) ? htmlspecialchars(number_format((float) $raw['full_capacity_ah'], 1)) : '—' ?></span><span class="stat__label">Capacity (Ah)</span></div>
            <div class="stat"><span class="stat__value"><?= isset($raw['remaining_capacity_ah']) ? htmlspecialchars(number_format((float) $raw['remaining_capacity_ah'], 1)) : '—' ?></span><span class="stat__label">Rem. Cap. (Ah)</span></div>
            <div class="stat"><span class="stat__value"><?= $latest['temp_max_c'] !== null ? htmlspecialchars((string) $latest['temp_max_c']) : '—' ?></span><span class="stat__label">High Temp (°C)</span></div>
            <div class="stat"><span class="stat__value">LFP</span><span class="stat__label">Cell Type</span></div>
        </div>

        <div class="current-power-row">
            <span><i class="ico">A</i> Prąd (A): <strong><?= $current !== null ? htmlspecialchars((string) $current) : '—' ?></strong></span>
            <span><i class="ico">W</i> Moc (W): <strong><?= $powerW !== null ? htmlspecialchars(number_format($powerW, 1)) : '—' ?></strong></span>
        </div>
        <div class="current-power-row current-power-row--meta">
            <span>Status: <strong><?= htmlspecialchars($currentStatusLabel) ?></strong></span>
            <span>Odczyt: <strong><?= htmlspecialchars((string) ($latest['recorded_at'] ?? '—')) ?></strong></span>
        </div>

        <div class="chart-range-buttons" data-device-id="<?= (int) $device['id'] ?>">
            <button type="button" class="chart-range-btn is-active" data-range="1h">1h</button>
            <button type="button" class="chart-range-btn" data-range="1d">1d</button>
            <button type="button" class="chart-range-btn" data-range="1w">1w</button>
            <button type="button" class="chart-range-btn" data-range="1m">1m</button>
        </div>
        <canvas class="device-card__chart" data-device-id="<?= (int) $device['id'] ?>"></canvas>

        <?php if (!empty($cellVoltages) || !empty($cellResistances) || !empty($raw)): ?>
        <details class="device-card__details">
            <summary>Szczegóły cel i parametry</summary>

            <?php if (!empty($cellVoltages)): ?>
            <h3 class="details__heading">Napięcia cel (V)</h3>
            <div class="cell-grid">
                <?php
                    $maxMv = max($cellVoltages);
                    $minMv = min($cellVoltages);
                ?>
                <?php foreach ($cellVoltages as $i => $mv): ?>
                <div class="cell-chip">
                    <span class="cell-chip__num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="cell-chip__value <?= $mv == $maxMv ? 'cell-chip__value--high' : ($mv == $minMv ? 'cell-chip__value--low' : '') ?>">
                        <?= htmlspecialchars(number_format($mv / 1000, 3)) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($cellResistances)): ?>
            <h3 class="details__heading">Rezystancja wyrównywania (Ω)</h3>
            <div class="cell-grid">
                <?php foreach ($cellResistances as $i => $ohm): ?>
                <div class="cell-chip">
                    <span class="cell-chip__num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="cell-chip__value"><?= htmlspecialchars(number_format((float) $ohm, 3)) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <h3 class="details__heading">Pozostałe parametry</h3>
            <dl class="device-card__raw">
                <?php foreach ($raw as $key => $value): ?>
                    <?php if (in_array($key, ['cell_voltages_mv', 'cell_resistances_ohm'], true)) continue; ?>
                    <?php
                        if (is_bool($value)) $displayValue = $value ? 'true' : 'false';
                        elseif ($value === null) $displayValue = '—';
                        elseif (is_scalar($value)) $displayValue = (string) $value;
                        else $displayValue = json_encode($value);
                    ?>
                <dt><?= htmlspecialchars((string) $key) ?></dt>
                <dd><?= htmlspecialchars($displayValue) ?></dd>
                <?php endforeach; ?>
            </dl>
        </details>
        <?php endif; ?>

        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
