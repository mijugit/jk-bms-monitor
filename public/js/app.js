document.addEventListener('DOMContentLoaded', () => {
    const grid = document.querySelector('.device-grid');
    if (!grid) return;
    const refreshStatus = document.querySelector('#refresh-status');
    const charts = new Map();
    let receivedAt = performance.now();
    let refreshing = false;
    let sessionExpired = false;
    const offlineAfter = Number(grid.dataset.offlineAfter || 180);
    const elapsed = () => Math.floor((performance.now() - receivedAt) / 1000);
    const ageLabel = seconds => seconds < 60 ? `${seconds} s temu` : seconds < 3600 ? `${Math.floor(seconds / 60)} min temu` : seconds < 86400 ? `${Math.floor(seconds / 3600)} godz. temu` : `${Math.floor(seconds / 86400)} dni temu`;
    const refreshWindow = 30;

    function updateAges() {
        grid.querySelectorAll('[data-reading-age]').forEach(node => {
            if (node.dataset.readingAge !== '') node.textContent = ageLabel(Number(node.dataset.readingAge) + elapsed());
        });
        grid.querySelectorAll('.refresh-zigzag').forEach(path => {
            const age = Number(path.closest('.device-card')?.querySelector('[data-reading-age]')?.dataset.readingAge);
            if (!Number.isFinite(age)) return;
            const progress = ((age + elapsed()) % refreshWindow) / refreshWindow * 100;
            path.style.strokeDasharray = `${progress} 100`;
            path.dataset.refreshProgress = String(progress);
        });
        grid.querySelectorAll('.device-card').forEach(card => {
            if (card.dataset.seenAge === '' || Number(card.dataset.seenAge) + elapsed() > offlineAfter) {
                card.classList.add('is-stale');
                const badge = card.querySelector('.badge');
                badge.className = 'badge badge--offline';
                badge.textContent = 'Brak połączenia';
                const message = card.querySelector('.status-message');
                if (message && !message.textContent.startsWith('Brak połączenia')) {
                    const alarm = card.matches('.device-card--warning, .device-card--critical');
                    message.textContent = 'Brak połączenia — widoczne są ostatnie zapisane dane.' + (alarm ? ` Ostatni odczyt: ${message.textContent}.` : '');
                }
                const label = card.querySelector('.power-display .metric-label');
                if (label && !label.textContent.endsWith(' (ostatni odczyt)')) label.textContent += ' (ostatni odczyt)';
            }
        });
    }

    // A hard colour stop at the physical zero line also splits segments crossing zero.
    function currentColour(context) {
        const { ctx, chartArea, scales } = context.chart;
        if (!chartArea || !scales.current) return '#68bfff';
        const zero = scales.current.getPixelForValue(0);
        if (zero >= chartArea.bottom) return '#68bfff';
        if (zero <= chartArea.top) return '#ff4d4f';
        const fraction = (zero - chartArea.top) / (chartArea.bottom - chartArea.top);
        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        gradient.addColorStop(0, '#68bfff');
        gradient.addColorStop(fraction, '#68bfff');
        gradient.addColorStop(fraction, '#ff4d4f');
        gradient.addColorStop(1, '#ff4d4f');
        return gradient;
    }

    async function loadChart(entry) {
        const request = ++entry.request;
        const feedback = entry.panel.querySelector('.chart-feedback');
        if (typeof Chart === 'undefined') {
            feedback.textContent = 'Nie udało się załadować wykresu. Odśwież stronę, aby spróbować ponownie.';
            return;
        }
        if (!entry.chart) feedback.textContent = 'Ładowanie historii…';
        try {
            const response = await fetch(`/api/history?device_id=${encodeURIComponent(entry.id)}&range=${entry.range}`, { cache: 'no-store', signal: AbortSignal.timeout(15000) });
            if (!response.ok || response.redirected) throw new Error('History unavailable');
            const data = await response.json();
            if (!Array.isArray(data.points)) throw new Error('Invalid history');
            if (request !== entry.request || !entry.panel.isConnected) return;
            const points = data.points;
            const labels = points.map(p => entry.range === '1h' || entry.range === '1d' ? p.t.slice(11, 16) : `${p.t.slice(8, 10)}.${p.t.slice(5, 7)}`);
            const datasets = [
                { label: 'Naładowanie (%)', data: points.map(p => p.soc_percent == null ? null : Number(p.soc_percent)), borderColor: '#3ddc84', yAxisID: 'soc' },
                { label: 'Prąd: + ładowanie / − rozładowanie (A)', data: points.map(p => p.current_amps == null ? null : Number(p.current_amps)), borderColor: currentColour, yAxisID: 'current' },
            ].map(dataset => ({ ...dataset, borderWidth: 2, pointRadius: 0, pointHitRadius: 12, tension: .15, spanGaps: false }));
            if (entry.chart) {
                entry.chart.data = { labels, datasets };
                entry.chart.update('none');
            } else {
                entry.chart = new Chart(entry.panel.querySelector('canvas'), {
                    type: 'line', data: { labels, datasets },
                    options: {
                        locale: 'pl-PL', responsive: true, maintainAspectRatio: false, animation: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { labels: { boxWidth: 10, color: '#9aa0ab', font: { size: 11 } } }, tooltip: { callbacks: { title: items => entry.points[items[0]?.dataIndex]?.t || '' } } },
                        scales: {
                            x: { ticks: { maxTicksLimit: 5, maxRotation: 0, color: '#9aa0ab', font: { size: 11 } }, grid: { display: false } },
                            soc: { position: 'left', min: 0, max: 100, ticks: { color: '#3ddc84', font: { size: 11 } }, grid: { color: '#ffffff08' } },
                            current: { position: 'right', ticks: { color: context => context.tick.value < 0 ? '#ff4d4f' : '#68bfff', font: { size: 11 } }, grid: { drawOnChartArea: false } },
                        },
                    },
                });
            }
            entry.points = points;
            feedback.textContent = points.length ? '' : 'Brak odczytów w wybranym okresie.';
            entry.panel.querySelectorAll('[data-range]').forEach(button => {
                const active = button.dataset.range === entry.range;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
        } catch {
            if (request !== entry.request) return;
            // Never leave the old range's curve labelled as the newly requested range.
            if (entry.chart) { entry.chart.destroy(); entry.chart = null; }
            feedback.textContent = 'Nie udało się pobrać historii. Wybierz zakres, aby ponowić.';
        }
    }

    function initCharts() {
        grid.querySelectorAll('.history-panel').forEach(panel => {
            const id = panel.closest('.device-card').dataset.deviceId;
            if (charts.has(id)) return;
            const entry = { id, panel, range: '1h', chart: null, request: 0, points: [] };
            charts.set(id, entry);
            panel.querySelector('.chart-range-buttons').addEventListener('click', event => {
                const button = event.target.closest('[data-range]');
                if (!button) return;
                entry.range = button.dataset.range;
                loadChart(entry);
            });
            loadChart(entry);
        });
    }

    function refresh() {
        if (refreshing || document.hidden) return;
        refreshing = true;
        // A full navigation is deliberate: it follows the same reliable path as F5,
        // bypasses DOM patching edge cases, and always re-reads current BMS values.
        window.location.reload();
    }
    initCharts();
    updateAges();
    setInterval(updateAges, 1000);
    setInterval(refresh, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
});
