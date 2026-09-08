// Renders one Chart.js line chart per .device-card__chart canvas, fed by the
// history data embedded in its data-history attribute (FR-006).
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') {
        return;
    }

    document.querySelectorAll('.device-card__chart').forEach((canvas) => {
        let history = [];
        try {
            history = JSON.parse(canvas.dataset.history || '[]');
        } catch (e) {
            return;
        }

        if (!history.length) {
            return;
        }

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: history.map((point) => point.recorded_at),
                datasets: [{
                    label: 'SOC %',
                    data: history.map((point) => point.value),
                    borderColor: '#3ddc84',
                    tension: 0.2,
                    pointRadius: history.length > 1 ? 0 : 3,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { display: false },
                    y: { beginAtZero: true, suggestedMax: 100 },
                },
            },
        });
    });
});
