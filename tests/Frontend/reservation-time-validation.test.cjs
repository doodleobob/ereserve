const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync('public/js/reservation-time-validation.js', 'utf8');

function setup({date = '2026-10-09', start = '23:00', end = '02:00', nextDay = '1', clock = '2026-10-09T22:55:00+08:00', hidden = false, action, original = {}} = {}) {
    let timestamp = Date.parse(clock), busy = false;
    const handlers = {}, intervals = [], windowHandlers = [], notices = [];
    const field = (type, value) => ({
        type, value, min: '', validityMessage: '', attributes: {},
        setCustomValidity(message) { this.validityMessage = message; },
        setAttribute(name, value) { this.attributes[name] = value; },
        getAttribute(name) { return this.attributes[name]; },
        removeAttribute(name) { if (name === 'min') this.min = ''; delete this.attributes[name]; },
        closest: () => null, after: node => notices.push(node),
    });
    const fields = {reservation_date: field(hidden ? 'hidden' : 'date', date), start_time: field(hidden ? 'hidden' : 'select-one', start), end_time: field(hidden ? 'hidden' : 'select-one', end), end_next_day: field('select-one', nextDay)};
    if (action !== undefined) fields.action = field('hidden', action);
    const button = {disabled: false};
    const form = {
        dataset: original,
        elements: {namedItem: name => fields[name]},
        closest: () => ({hasAttribute: () => busy}),
        querySelector: () => null, querySelectorAll: () => [button],
        matches: () => true, reports: 0, reportValidity() { this.reports++; },
    };
    Object.entries(fields).forEach(([name, control]) => { control.name = name; control.closest = selector => selector === 'form[data-future-reservation]' ? form : null; });
    const document = {
        currentScript: {dataset: {serverNow: String(timestamp)}},
        querySelectorAll: () => [form],
        createElement: () => ({setAttribute() {}}),
        addEventListener(name, callback, capture) { (handlers[name] ||= []).push({callback, capture}); },
    };
    // The device wall clock is deliberately wrong; the reference comes from Laravel.
    const deviceClock = timestamp - 3 * 86400000;
    const context = {document, Intl, URLSearchParams, Date: {now: () => deviceClock + timestamp - Date.parse(clock), parse: Date.parse}, Number, Math, Object, WeakMap, Set,
        window: {addEventListener: (name, callback) => windowHandlers.push({name, callback}), EReserveValidation: {set(form, source, errors) { notices[0] = {hidden: !Object.keys(errors).length, textContent: Object.values(errors).join(' ')}; }}},
        setInterval: callback => intervals.push(callback), setTimeout: callback => callback(),
    };
    vm.runInNewContext(script, context);
    const dispatch = (name, target = fields.start_time) => {
        const event = {target, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }};
        for (const handler of handlers[name] || []) { handler.callback(event); if (event.stopped) break; }
        return event;
    };
    return {fields, button, form, notices, dispatch, handlers, tick: () => intervals[0](), advance: ms => { timestamp += ms; }, setBusy: value => { busy = value; }};
}

test('minute selections are rejected and the date advances when no future whole hour remains', () => {
    const {fields, button} = setup({clock: '2026-10-09T23:00:30+08:00', start: '23:01'});
    assert.equal(fields.reservation_date.min, '2026-10-10');
    assert.match(fields.start_time.validityMessage, /whole-hour/);
    assert.equal(button.disabled, true);
});

test('past dates, earlier clock times and exact-now times are blocked', () => {
    for (const data of [{date: '2026-10-08'}, {start: '20:00'}, {start: '22:30'}, {start: '22:55'}]) {
        const {fields, button, notices} = setup(data);
        assert.equal(button.disabled, true);
        assert.equal(notices[0].hidden, false);
        assert.match(fields.reservation_date.validityMessage || fields.start_time.validityMessage, /past|future/);
    }
});

test('future dates have no today-only start minimum, including midnight', () => {
    const {fields, button} = setup({date: '2026-10-10', start: '00:00', end: '01:00', nextDay: '0'});
    assert.equal(fields.start_time.min, ''); assert.equal(button.disabled, false);
});

test('valid overnight times are accepted; equal start and end are rejected', () => {
    const valid = setup({start: '23:00', end: '02:00'});
    assert.equal(valid.fields.end_time.validityMessage, ''); assert.equal(valid.button.disabled, false);
    const invalid = setup({start: '23:00', end: '23:00'});
    assert.match(invalid.fields.end_time.validityMessage, /different/); assert.equal(invalid.button.disabled, true);
});

test('time passing while open updates validity and blocks hidden calendar fields on submit', () => {
    const view = setup({clock: '2026-10-09T22:55:00+08:00', start: '23:00', hidden: true});
    assert.equal(view.button.disabled, false);
    view.advance(15 * 60000);
    const event = view.dispatch('submit', view.form);
    assert.equal(event.prevented, true); assert.equal(event.stopped, true);
    assert.match(view.fields.start_time.validityMessage, /must be in the future/);
    assert.equal(view.button.disabled, true);
    assert.equal(view.handlers.submit[0].capture, true);
});

test('input changes immediately clear an expired start and restore submission', () => {
    const view = setup({start: '22:30'});
    view.fields.start_time.value = '23:00'; view.dispatch('input');
    assert.equal(view.fields.start_time.validityMessage, ''); assert.equal(view.button.disabled, false);
});

test('the last Philippine minute advances the date minimum and midnight refreshes it', () => {
    const view = setup({clock: '2026-10-09T23:59:00+08:00', start: '23:59'});
    assert.equal(view.fields.reservation_date.min, '2026-10-10'); assert.equal(view.button.disabled, true);
    view.advance(60000); view.tick();
    view.fields.reservation_date.value = '2026-10-10'; view.fields.start_time.value = '01:00'; view.fields.end_next_day.value = '0'; view.dispatch('change');
    assert.equal(view.fields.start_time.validityMessage, ''); assert.equal(view.button.disabled, false);
});

test('cancellation is exempt from scheduling checks and can follow an invalid reschedule', () => {
    const view = setup({start: '20:00', action: 'reschedule'});
    assert.equal(view.button.disabled, true);
    view.fields.action.value = 'cancel'; view.tick();
    assert.equal(view.fields.start_time.validityMessage, ''); assert.equal(view.button.disabled, false);
    assert.equal(view.dispatch('submit', view.form).prevented, false);
});

test('periodic validation does not unlock or alter an in-flight modal', () => {
    const view = setup(); view.button.disabled = true; view.setBusy(true);
    view.advance(30 * 60000); view.tick();
    assert.equal(view.button.disabled, true); assert.equal(view.fields.start_time.validityMessage, '');
    view.setBusy(false); view.tick(); assert.match(view.fields.start_time.validityMessage, /future/);
});

test('an earlier end requires explicit next-day intent', () => {
    const view = setup({nextDay: '0'});
    assert.equal(view.button.disabled, true);
    assert.match(view.fields.end_time.validityMessage, /Select Next day/);
    view.fields.end_next_day.value = '1'; view.dispatch('change');
    assert.equal(view.button.disabled, false);
});

test('legacy minute values remain valid only while their original schedule is unchanged', () => {
    const view = setup({date: '2026-10-10', start: '13:15', end: '15:45', nextDay: '0', original: {originalDate: '2026-10-10', originalStart: '13:15', originalEnd: '15:45'}});
    assert.equal(view.button.disabled, false);
    view.fields.reservation_date.value = '2026-10-11'; view.dispatch('change');
    assert.match(view.fields.start_time.validityMessage, /whole-hour/);
    assert.equal(view.button.disabled, true);
});
