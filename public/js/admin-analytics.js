(() => {
    'use strict';
    const root = document.getElementById('admin-analytics');
    if (!root) return;
    const period = root.querySelector('#analytics-period');
    const customDates = root.querySelectorAll('input[type="date"]');
    const updateDates = () => customDates.forEach(input => { input.disabled = period.value !== 'custom'; });
    updateDates();
    period.addEventListener('change', () => {
        updateDates();
        if (period.value !== 'custom') period.form.requestSubmit();
    });
    // Tables remain usable without JavaScript or if the chart library cannot load.
    if (typeof window.Chart !== 'function') return;
    const data = JSON.parse(document.getElementById('admin-analytics-data').textContent);
    const labels = data.series.map(point => point.date);
    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const trend = (id, label, values, money = false) => {
        const canvas = document.getElementById(id);
        canvas.parentElement.hidden = false;
        new window.Chart(canvas, {
            type: 'bar',
            data: { labels, datasets: [{ label, data: values, backgroundColor: '#2442ba', borderRadius: 3 }] },
            options: {
                responsive: true, maintainAspectRatio: false, animation: reducedMotion ? false : undefined,
                plugins: { legend: { display: false }, tooltip: { callbacks: {
                    label: context => `${label}: ${money ? currency.format(context.parsed.y) : context.parsed.y}`,
                } } },
                scales: {
                    x: { ticks: { maxTicksLimit: 6 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: money ? { callback: value => currency.format(value) } : { precision: 0 } },
                },
            },
        });
    };
    trend('analytics-requests', 'Requests', data.series.map(point => point.requests));
    trend('analytics-collection', 'Collected', data.series.map(point => Number(point.collected)), true);
    const canvas = document.getElementById('analytics-status');
    if (data.pending + data.booked + data.rejected > 0) {
        canvas.parentElement.hidden = false;
        new window.Chart(canvas, {
            type: 'doughnut',
            data: { labels: ['Pending', 'Booked', 'Rejected'], datasets: [{ data: [data.pending, data.booked, data.rejected], backgroundColor: ['#eab308', '#16834a', '#dc3545'] }] },
            options: { responsive: true, maintainAspectRatio: false, animation: reducedMotion ? false : undefined, plugins: { legend: { position: 'bottom' } } },
        });
    }
})();
