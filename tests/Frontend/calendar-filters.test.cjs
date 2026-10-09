const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const filters = fs.readFileSync('resources/views/calendar/filters.blade.php', 'utf8');
const script = fs.readFileSync('public/js/page-filters.js', 'utf8');
function change(id, field) {
    const tag = filters.split('\n').find(line => line.includes('id="' + id + '"'));
    assert.ok(tag, 'The existing calendar field must remain in Blade');
    let callback;
    field.addEventListener = (event, handler) => { assert.equal(event, 'change'); callback = handler; };
    vm.runInNewContext(script, {document: {
        querySelectorAll() { return []; },
        getElementById(key) { return key === id ? field : null; },
    }});
    assert.equal(typeof callback, 'function');
    callback();
}
test('barangay change clears the old resource and submits automatically', () => {
    let submitted = 0;
    const form = {elements: {facility: {value: 'previous-court', selectedOptions:[{dataset:{barangay:'Washington',type:'facility'}}]}, barangay:{value:'Taft'}, type:{value:'all'}}, submit() { submitted++; }};
    change('calendar-barangay', {form, value: 'Taft'});
    assert.equal(form.elements.facility.value, 'all');
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
    assert.match(filters, /id="facility"[^>]*data-auto-submit/);
    vm.runInNewContext(script, {document: {
        querySelectorAll(selector) { assert.equal(selector, '[data-auto-submit]'); return [field]; },
        getElementById() { return null; },
    }});
    callback();
    assert.equal(submitted, 1);
});

test('calendar and resident reservations load the same extracted auto-submit implementation', () => {
    const dashboard = fs.readFileSync('resources/views/calendar/index.blade.php', 'utf8');
    const reservations = fs.readFileSync('resources/views/reservations/index.blade.php', 'utf8');
    assert.ok(dashboard.includes("asset('js/page-filters.js')"));
    assert.match(reservations, /@unless\(\$isAdmin\)\s*@push\('scripts'\)\s*<script[^>]*page-filters\.js/);
    let submitted = 0;
    const fields = ['pending', 'facility'].map(value => ({value, form: {submit() { submitted++; }},
        addEventListener(event, handler) { assert.equal(event, 'change'); this.change = handler; }}));
    vm.runInNewContext(script, {document: {querySelectorAll() { return fields; }, getElementById() { return null; }}});
    fields.forEach(field => field.change());
    assert.equal(submitted, 2);
    assert.deepEqual(fields.map(field => field.value), ['pending', 'facility']);
});

test('resource type change clears the resource while preserving barangay and date', () => {
    let submitted = 0;
    const form = {elements: {facility: {value: 'taft-court',selectedOptions:[{dataset:{barangay:'Taft',type:'facility'}}]}, type:{value:'equipment'}, barangay: {value: 'Taft'}, date: {value: '2026-10-10'}}, submit() { submitted++; }};
    change('resource-type', {form, value: 'equipment'});
    assert.equal(form.elements.facility.value, 'all');
    assert.equal(form.elements.barangay.value, 'Taft');
    assert.equal(form.elements.date.value, '2026-10-10');
    assert.equal(submitted, 1);
});

test('valid resources survive matching type, All Types, and unchanged barangay selections', () => {
    for (const [id, type] of [['resource-type','all'], ['resource-type','facility'], ['calendar-barangay','facility']]) {
        let submitted = 0;
        const form = {elements:{facility:{value:'taft-court', selectedOptions:[{dataset:{barangay:'Taft',type:'facility'}}]}, barangay:{value:'Taft'},type:{value:type},date:{value:'2026-10-10'},month:{value:'2026-10'}},submit(){submitted++;}};
        change(id,{form});
        assert.equal(form.elements.facility.value,'taft-court');
        assert.equal(form.elements.date.value,'2026-10-10');
        assert.equal(form.elements.month.value,'2026-10');
        assert.equal(submitted,1);
    }
});

test('All Resources survives upstream changes and preserves the selected resource type', () => {
    for (const id of ['calendar-barangay','resource-type']) {
        let submitted=0;
        const form={elements:{facility:{value:'all',selectedOptions:[{dataset:{}}]},barangay:{value:'Taft'},type:{value:'equipment'},date:{value:'2026-10-10'}},submit(){submitted++;}};
        change(id,{form});
        assert.equal(form.elements.facility.value,'all');
        assert.equal(form.elements.type.value,'equipment');
        assert.equal(form.elements.date.value,'2026-10-10');
        assert.equal(submitted,1);
    }
});
