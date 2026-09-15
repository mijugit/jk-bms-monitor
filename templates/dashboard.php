<?php
$number = static fn($value, int $precision = 1): string => $value === null ? '—' : number_format((float) $value, $precision, ',', ' ');
?>
<div class="dashboard-heading">
    <div><p class="eyebrow">Monitor energii</p><h1>Twoje banki energii</h1></div>
    <p class="refresh-status" role="status" id="refresh-status">Odczyty odświeżane co 30 s</p>
</div>
<div class="device-grid" data-offline-after="<?= (int) ($offlineAfter ?? 180) ?>">
<?php if (empty($rows)): ?>
    <div class="empty-state"><h2>Jeszcze nie ma banków energii</h2><p>Po podłączeniu pierwszego urządzenia jego odczyty pojawią się tutaj.</p></div>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
<?php
    $device = $row['device'];
    $latest = $row['latest'];
    $online = $row['online'];
    $status = $latest['status'] ?? 'unknown';
    $visualStatus = $online ? $status : 'unknown';
    $raw = $latest ? (json_decode((string) ($latest['raw_json'] ?? '{}'), true) ?: []) : [];
    $soc = $latest['soc_percent'] ?? null;
    $voltage = $latest['pack_voltage'] ?? null;
    $current = $latest['current_amps'] ?? null;
    $power = $voltage !== null && $current !== null ? abs((float) $voltage * (float) $current) : null;
    $direction = $current === null ? 'Brak danych' : ($current > 0.05 ? 'Ładowanie' : ($current < -0.05 ? 'Rozładowanie' : 'Spoczynek'));
    $statusText = ['normal' => 'Parametry w normie', 'warning' => 'Ostrzeżenie — sprawdź parametry banku', 'critical' => 'Stan krytyczny — sprawdź bank natychmiast'][$status] ?? 'Brak danych o stanie banku';
    if (!$online) $statusText = 'Brak połączenia — widoczne są ostatnie zapisane dane.' . (in_array($status, ['warning', 'critical'], true) ? ' Ostatni odczyt: ' . $statusText . '.' : '');
    $min = $latest['cell_voltage_min_mv'] ?? null;
    $max = $latest['cell_voltage_max_mv'] ?? null;
    $age = isset($latest['reading_age_seconds']) ? max(0, (int) $latest['reading_age_seconds']) : null;
    $ageText = $age === null ? 'brak odczytu' : ($age < 60 ? $age . ' s temu' : ($age < 3600 ? floor($age / 60) . ' min temu' : ($age < 86400 ? floor($age / 3600) . ' godz. temu' : floor($age / 86400) . ' dni temu')));
    $stats = [
        ['Napięcie banku', $voltage, 2, 'V'], ['Prąd', $current, 2, 'A'],
        ['Pojemność pełna', $raw['full_capacity_ah'] ?? null, 1, 'Ah'], ['Pojemność pozostała', $raw['remaining_capacity_ah'] ?? null, 1, 'Ah'],
        ['Najwyższe napięcie ogniwa', $max !== null ? $max / 1000 : null, 3, 'V'], ['Najniższe napięcie ogniwa', $min !== null ? $min / 1000 : null, 3, 'V'],
        ['Różnica napięć ogniw', $max !== null && $min !== null ? $max - $min : null, 0, 'mV'], ['Najwyższa temperatura', $latest['temp_max_c'] ?? null, 1, '°C'],
    ];
?>
<section class="device-card device-card--<?= htmlspecialchars($visualStatus) ?>" data-device-id="<?= (int) $device['id'] ?>" data-seen-age="<?= isset($device['last_seen_age_seconds']) ? max(0, (int) $device['last_seen_age_seconds']) : '' ?>">
    <div class="device-card__live">
        <header class="device-card__header">
            <h2><?= htmlspecialchars((string) $device['name']) ?></h2>
            <span class="badge badge--<?= $online ? 'online' : 'offline' ?>"><?= $online ? 'Połączony' : 'Brak połączenia' ?></span>
        </header>
        <p class="reading-age">Ostatni odczyt <span data-reading-age="<?= $age ?? '' ?>" title="<?= htmlspecialchars((string) ($latest['recorded_at'] ?? '')) ?>"><?= htmlspecialchars($ageText) ?></span></p>
        <?php if ($latest === null): ?>
            <div class="empty-state"><p>Oczekiwanie na pierwszy odczyt</p></div>
        <?php else: ?>
        <div class="energy-overview">
            <div class="soc-display">
                <div class="soc-ring">
                    <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="soc-ring__track" cx="60" cy="60" r="51"/><circle class="soc-ring__fill" cx="60" cy="60" r="51" pathLength="100" stroke-dasharray="<?= $soc === null ? 0 : max(0, min(100, (float) $soc)) ?> 100" transform="rotate(-90 60 60)"/></svg>
                    <strong><?= $number($soc, 0) ?><small>%</small></strong>
                </div>
                <span class="metric-label">Naładowanie</span>
            </div>
            <div class="power-display">
                <span class="metric-label"><?= $online ? 'Moc banku' : 'Ostatnia moc banku' ?></span>
                <strong class="power-value"><?= $number($power, 1) ?> <small>W</small></strong>
                <span class="energy-direction"><?= htmlspecialchars($direction) ?></span>
            </div>
        </div>
        <p class="status-message status-message--<?= htmlspecialchars($visualStatus) ?>"><?= htmlspecialchars($statusText) ?></p>
        <dl class="stat-grid">
            <?php foreach ($stats as [$label, $value, $precision, $unit]): ?>
            <div class="stat"><dt class="stat__label"><?= htmlspecialchars($label) ?></dt><dd class="stat__value"><?= $number($value, $precision) ?> <small><?= $unit ?></small></dd></div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </div>
    <?php if ($latest !== null): ?>
    <section class="history-panel" aria-label="Historia odczytów">
        <div class="history-heading"><h3>Historia</h3><span>Naładowanie i prąd</span></div>
        <div class="chart-range-buttons" aria-label="Zakres historii">
            <?php foreach (['1h' => '1 godz.', '1d' => '24 godz.', '1w' => '7 dni', '1m' => '30 dni'] as $range => $label): ?>
            <button type="button" class="chart-range-btn<?= $range === '1h' ? ' is-active' : '' ?>" data-range="<?= $range ?>" aria-pressed="<?= $range === '1h' ? 'true' : 'false' ?>"><?= $label ?></button>
            <?php endforeach; ?>
        </div>
        <div class="chart-container"><canvas class="device-card__chart" data-device-id="<?= (int) $device['id'] ?>" role="img" aria-label="Historia naładowania w procentach i prądu w amperach"></canvas></div>
        <p class="chart-feedback" role="status"></p>
    </section>
    <details class="device-card__details">
        <summary>Ogniwa i szczegóły techniczne</summary>
        <dl class="switch-states">
            <?php foreach (['charge_mosfet_on' => 'Ładowanie dozwolone', 'discharge_mosfet_on' => 'Rozładowanie dozwolone'] as $key => $label): ?>
            <div><dt><?= $label ?></dt><dd><?= !isset($raw[$key]) ? 'Brak danych' : ($raw[$key] ? 'Tak' : 'Nie') ?></dd></div>
            <?php endforeach; ?>
            <div><dt>Balansowanie</dt><dd><?= [0 => 'Wyłączone', 1 => 'Ładowanie', 2 => 'Rozładowanie'][$raw['balancer_status'] ?? -1] ?? 'Brak danych' ?></dd></div>
            <div><dt>Prąd balansowania</dt><dd><?= $number($raw['balance_current_amps'] ?? null, 3) ?> A</dd></div>
            <div><dt>Typ ogniw</dt><dd>LiFePO₄</dd></div>
        </dl>
        <?php foreach (['cell_voltages_mv' => ['Napięcia ogniw', 'V', 1000], 'cell_resistances_ohm' => ['Rezystancja wyrównywania', 'Ω', 1]] as $key => [$label, $unit, $divisor]): ?>
        <?php if (!empty($raw[$key])): ?>
        <h3 class="details__heading"><?= $label ?> (<?= $unit ?>)</h3>
        <div class="cell-grid">
            <?php foreach ($raw[$key] as $i => $value): ?>
            <div class="cell-chip"><span class="cell-chip__num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><span><?= $number($value / $divisor, 3) ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
        <details class="raw-details"><summary>Dane diagnostyczne BMS</summary>
        <dl class="device-card__raw">
            <?php foreach ($raw as $key => $value): ?>
            <?php if (is_array($value)) continue; ?>
            <dt><?= htmlspecialchars((string) $key) ?></dt><dd><?= htmlspecialchars(is_bool($value) ? ($value ? 'Tak' : 'Nie') : (string) ($value ?? '—')) ?></dd>
            <?php endforeach; ?>
        </dl></details>
    </details>
    <?php endif; ?>
</section>
<?php endforeach; ?>
</div>
