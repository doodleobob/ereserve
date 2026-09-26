(() => {
    'use strict';
    const root = document.getElementById('super-admin-analytics');
    if (!root) return;
    const period = root.querySelector('#super-analytics-period');
    const start = root.querySelector('#super-analytics-start');
    const end = root.querySelector('#super-analytics-end');
    const presets = JSON.parse(period.form.dataset.datePresets);
    let customDates = period.value === 'custom' ? [start.value, end.value] : null;
    let previousPeriod = period.value;
    const updateDates = () => {
        if (previousPeriod === 'custom') customDates = [start.value, end.value];
        const custom = period.value === 'custom';
        if (custom) {
            if (customDates) [start.value, end.value] = customDates;
        } else {
            const dates = presets[period.value];
            start.value = dates?.start ?? '';
            end.value = dates?.end ?? '';
        }
        for (const input of [start, end]) {
            input.disabled = !custom;
            input.required = custom;
        }
        end.min = custom ? start.value : '';
        previousPeriod = period.value;
    };
    updateDates();
    period.addEventListener('change', updateDates);
    start.addEventListener('input', () => { end.min = start.value; });
    // Filtering still works when the chart library is unavailable.
    if (typeof window.Chart !== 'function') return;
    const data = JSON.parse(document.getElementById('super-admin-analytics-data').textContent);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const draw = (id, type, labels, values, label) => {
        const canvas = root.querySelector('#' + id);
        canvas.parentElement.hidden = false;
        const doughnut = type === 'doughnut';
        new window.Chart(canvas, {
            type,
            data: { labels, datasets: [{
                label, data: values,
                backgroundColor: doughnut ? ['#eab308', '#16834a', '#dc3545'] : '#2442ba',
                borderColor: doughnut ? '#ffffff' : '#2442ba',
                borderWidth: 2,
            }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: reducedMotion ? false : undefined,
                plugins: { legend: { display: doughnut, position: 'bottom' } },
                ...(doughnut ? {} : { scales: {
                    x: { ticks: { maxTicksLimit: 6 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                } }),
            },
        });
    };
    if (data.byBarangay.length) {
        draw('super-analytics-barangays', 'bar', data.byBarangay.map(row => row.barangay), data.byBarangay.map(row => row.count), 'Reservations');
    }
    draw('super-analytics-trend', 'line', data.series.map(row => row.month), data.series.map(row => row.count), 'Reservations');
    if (Object.values(data.statuses).some(count => count > 0)) {
        draw('super-analytics-status', 'doughnut', Object.keys(data.statuses), Object.values(data.statuses), 'Reservations');
    }
})();
