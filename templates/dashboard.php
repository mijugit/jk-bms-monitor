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
    ?>
    <section class="device-card device-card--<?= htmlspecialchars($status ?? 'unknown') ?>">
        <header class="device-card__header">
            <h2><?= htmlspecialchars((string) $device['name']) ?></h2>
            <span class="badge badge--<?= $online ? 'online' : 'offline' ?>"><?= $online ? 'online' : 'offline' ?></span>
            <?php if ($status): ?>
            <span class="badge badge--status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
            <?php endif; ?>
        </header>

        <?php if ($latest === null): ?>
        <p>Brak jeszcze żadnego odczytu z tego urządzenia.</p>
        <?php else: ?>
        <dl class="device-card__values">
            <dt>SOC</dt><dd><?= htmlspecialchars((string) ($latest['soc_percent'] ?? '—')) ?> %</dd>
            <dt>Napięcie</dt><dd><?= htmlspecialchars((string) ($latest['pack_voltage'] ?? '—')) ?> V</dd>
            <dt>Prąd</dt><dd><?= htmlspecialchars((string) ($latest['current_amps'] ?? '—')) ?> A</dd>
            <dt>Temp. max</dt><dd><?= htmlspecialchars((string) ($latest['temp_max_c'] ?? '—')) ?> °C</dd>
            <dt>Cela min</dt><dd><?= htmlspecialchars((string) ($latest['cell_voltage_min_mv'] ?? '—')) ?> mV</dd>
            <dt>Cela max</dt><dd><?= htmlspecialchars((string) ($latest['cell_voltage_max_mv'] ?? '—')) ?> mV</dd>
            <dt>Ostatni odczyt</dt><dd><?= htmlspecialchars((string) ($latest['recorded_at'] ?? '—')) ?></dd>
        </dl>

        <?php if (!empty($raw)): ?>
        <details class="device-card__raw">
            <summary>Wszystkie odczytane parametry</summary>
            <dl>
                <?php foreach ($raw as $key => $value): ?>
                <dt><?= htmlspecialchars((string) $key) ?></dt>
                <dd><?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value)) ?></dd>
                <?php endforeach; ?>
            </dl>
        </details>
        <?php endif; ?>

        <canvas class="device-card__chart" data-history='<?= htmlspecialchars(json_encode($row['history']), ENT_QUOTES) ?>'></canvas>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
