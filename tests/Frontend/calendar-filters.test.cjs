const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const filters = fs.readFileSync('resources/views/partials/calendar-filters.blade.php', 'utf8');
const dashboard = fs.readFileSync('resources/views/dashboard.blade.php', 'utf8');
function change(id, field) {
    const tag = filters.split('\n').find(line => line.includes('id="' + id + '"'));
    const handler = tag.match(/onchange="([^"]+)"/)[1];
    vm.runInNewContext('(function () {' + handler + '}).call(field)', {field});
}
test('barangay change clears the old resource and submits automatically', () => {
    let submitted = 0;
    const form = {elements: {facility: {value: 'previous-court'}}, submit() { submitted++; }};
    change('calendar-barangay', {form, value: 'Taft'});
    assert.equal(form.elements.facility.value, '');
    assert.equal(submitted, 1);
});
test('date change navigates to the selected month automatically', () => {
    let submitted = 0;
    const form = {elements: {month: {value: '2026-10'}}, submit() { submitted++; }};
    change('date', {form, value: '2027-02-10'});
    assert.equal(form.elements.month.value, '2027-02');
    assert.equal(submitted, 1);
});
test('resource selection immediately submits the selected filters', () => {
    let callback, submitted = 0;
    const field = {form: {submit() { submitted++; }}, addEventListener(event, handler) { assert.equal(event, 'change'); callback = handler; }};
    const script = dashboard.match(/<script>([\s\S]*?)<\/script>/)[1];
    vm.runInNewContext(script, {document: {querySelectorAll() { return [field]; }}});
    callback();
    assert.equal(submitted, 1);
});

test('resource type change clears the resource while preserving barangay and date', () => {
    let submitted = 0;
    const form = {elements: {facility: {value: 'taft-court'}, barangay: {value: 'Taft'}, date: {value: '2026-10-10'}}, submit() { submitted++; }};
    change('resource-type', {form, value: 'equipment'});
    assert.equal(form.elements.facility.value, '');
    assert.equal(form.elements.barangay.value, 'Taft');
    assert.equal(form.elements.date.value, '2026-10-10');
    assert.equal(submitted, 1);
});
