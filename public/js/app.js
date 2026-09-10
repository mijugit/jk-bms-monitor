// Renders one Chart.js line chart per .device-card__chart canvas, fed by
// /api/history (FR-006). Each card has its own 1h/1d/1w/1m range buttons —
// clicking one re-fetches that device's history and updates the chart
// in place (no full page reload).
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') {
        return;
    }

    // "2026-09-10 20:15:00" -> "20:15" (short ranges) or "09-10" (long ranges).
    function formatLabel(t, range) {
        if (range === '1h' || range === '1d') {
            return t.slice(11, 16);
        }
        return t.slice(5, 10);
    }

    document.querySelectorAll('.device-card__chart').forEach((canvas) => {
        const deviceId = canvas.dataset.deviceId;
        if (!deviceId) {
            return;
        }

        const card = canvas.closest('.device-card');
        const buttonsWrap = card ? card.querySelector('.chart-range-buttons') : null;
        let chart = null;

        function loadRange(range) {
            fetch(`/api/history?device_id=${encodeURIComponent(deviceId)}&range=${encodeURIComponent(range)}`)
                .then((res) => res.json())
                .then((data) => {
                    const points = Array.isArray(data.points) ? data.points : [];
                    const labels = points.map((p) => formatLabel(p.t, range));
                    const soc = points.map((p) => (p.soc_percent !== null ? Number(p.soc_percent) : null));
                    const current = points.map((p) => (p.current_amps !== null ? Number(p.current_amps) : null));

                    if (chart) {
                        chart.data.labels = labels;
                        chart.data.datasets[0].data = soc;
                        chart.data.datasets[1].data = current;
                        chart.update();
                        return;
                    }

                    chart = new Chart(canvas, {
                        type: 'line',
                        data: {
                            labels,
                            datasets: [
                                {
                                    label: 'SOC %',
                                    data: soc,
                                    borderColor: '#3ddc84',
                                    yAxisID: 'ySoc',
                                    tension: 0.25,
                                    pointRadius: 0,
                                    spanGaps: true,
                                },
                                {
                                    label: 'Prąd (A)',
                                    data: current,
                                    borderColor: '#4fb8ff',
                                    yAxisID: 'yCurrent',
                                    tension: 0.25,
                                    pointRadius: 0,
                                    spanGaps: true,
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: {
                                    display: true,
                                    labels: { boxWidth: 10, font: { size: 10 }, color: '#9aa0ab' },
                                },
                            },
                            scales: {
                                x: {
                                    display: true,
                                    ticks: { maxTicksLimit: 6, font: { size: 9 }, color: '#9aa0ab' },
                                    grid: { display: false },
                                },
                                ySoc: {
                                    position: 'left',
                                    beginAtZero: true,
                                    suggestedMax: 100,
                                    ticks: { font: { size: 9 }, color: '#3ddc84' },
                                },
                                yCurrent: {
                                    position: 'right',
                                    grid: { drawOnChartArea: false },
                                    ticks: { font: { size: 9 }, color: '#4fb8ff' },
                                },
                            },
                        },
                    });
                })
                .catch(() => {});
        }

        if (buttonsWrap) {
            buttonsWrap.addEventListener('click', (e) => {
                const btn = e.target.closest('.chart-range-btn');
                if (!btn || !buttonsWrap.contains(btn)) {
                    return;
                }
                buttonsWrap.querySelectorAll('.chart-range-btn').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                loadRange(btn.dataset.range);
            });
        }

        loadRange('1h');
    });
});
