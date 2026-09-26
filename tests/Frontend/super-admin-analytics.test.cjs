const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const handlers = {};
const dates = [
    { value: '2026-01-01', addEventListener: (event, handler) => { handlers.startInput = handler; } },
    { value: '2026-09-26' },
];
const presets = {
    '7': { start: '2026-09-20', end: '2026-09-26' },
    '30': { start: '2026-08-28', end: '2026-09-26' },
    month: { start: '2026-09-01', end: '2026-09-26' },
    year: { start: '2026-01-01', end: '2026-09-26' },
    all: { start: '', end: '' },
};
const period = {
    value: 'year',
    form: { dataset: { datePresets: JSON.stringify(presets) }, requestSubmit: () => assert.fail('Only Apply should submit') },
    addEventListener: (event, handler) => { handlers[event] = handler; },
};
const root = {
    querySelector: selector => ({
        '#super-analytics-period': period,
        '#super-analytics-start': dates[0],
        '#super-analytics-end': dates[1],
    })[selector],
};
vm.runInNewContext(fs.readFileSync('public/js/super-admin-analytics.js', 'utf8'), {
    document: { getElementById: () => root },
    window: {}, // Filtering must work even when Chart.js cannot load.
});
for (const value of ['custom', '7', '30', 'month', 'year', 'all', 'custom']) {
    period.value = value;
    handlers.change();
    for (const input of dates) {
        assert.equal(input.disabled, value !== 'custom');
        assert.equal(input.required, value === 'custom');
    }
    if (value !== 'custom') {
        assert.deepEqual(dates.map(input => input.value), [presets[value].start, presets[value].end]);
    }
}
dates[0].value = '2026-08-01';
dates[1].value = '2026-08-31';
handlers.startInput();
assert.equal(dates[1].min, '2026-08-01');
period.value = 'all';
handlers.change();
assert.deepEqual(dates.map(input => input.value), ['', '']);
period.value = 'custom';
handlers.change();
assert.deepEqual(dates.map(input => input.value), ['2026-08-01', '2026-08-31']);
assert.equal(dates[1].min, '2026-08-01');
console.log('Passed preset display, disabled fields, All Time clearing, and custom date preservation without Chart.js.');
