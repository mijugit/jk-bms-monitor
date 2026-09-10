<h1>Banki energii</h1>

<?php if (empty($rows)): ?>
<p>Brak zarejestrowanych urządzeń. Dodaj wiersz w tabeli <code>devices</code> (nazwa lokalizacji + <code>device_key</code>) i skonfiguruj nim firmware ESP32.</p>
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
        $powerW      = ($voltage !== null && $current !== null) ? $voltage * $current : null;

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

        // Circular SOC gauge: full-circle stroke-dasharray trick, starts at 12 o'clock.
        $gaugeRadius = 52;
        $gaugeCircumference = 2 * M_PI * $gaugeRadius;
        $gaugeOffset = $gaugeCircumference * (1 - $socPercent / 100);
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
            <svg viewBox="0 0 120 120">
                <circle class="gauge__track" cx="60" cy="60" r="<?= $gaugeRadius ?>" />
                <circle class="gauge__fill gauge__fill--<?= htmlspecialchars($status ?? 'unknown') ?>"
                        cx="60" cy="60" r="<?= $gaugeRadius ?>"
                        stroke-dasharray="<?= round($gaugeCircumference, 2) ?>"
                        stroke-dashoffset="<?= round($gaugeOffset, 2) ?>" />
            </svg>
            <div class="gauge__label">
                <span class="gauge__percent"><?= $soc !== null ? htmlspecialchars((string) round((float) $soc)) : '—' ?><small>%</small></span>
            </div>
        </div>

        <div class="gauge__pills">
            <span class="pill"><?= $voltage !== null ? htmlspecialchars((string) $voltage) : '—' ?> V</span>
            <span class="pill"><?= $current !== null ? htmlspecialchars((string) $current) : '—' ?> A</span>
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

        <canvas class="device-card__chart" data-history='<?= htmlspecialchars(json_encode($row['history']), ENT_QUOTES) ?>'></canvas>

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
                <dt><?= htmlspecialchars((string) $key) ?></dt>
                <dd><?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value)) ?></dd>
                <?php endforeach; ?>
            </dl>
        </details>
        <?php endif; ?>

        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
