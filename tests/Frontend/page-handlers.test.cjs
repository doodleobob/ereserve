const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('both layouts keep the same PWA registration on window load', () => {
    const partial = fs.readFileSync('resources/views/partials/pwa-registration.blade.php', 'utf8');
    const script = partial.match(/<script>([\s\S]*?)<\/script>/)[1];
    for (const layout of ['auth', 'user']) {
        const blade = fs.readFileSync(`resources/views/components/layouts/${layout}.blade.php`, 'utf8');
        assert.equal(blade.split("@include('partials.pwa-registration')").length - 1, 1);
        const registrations = [];
        let load;
        vm.runInNewContext(script, {
            navigator: {serviceWorker: {register: url => registrations.push(url)}},
            window: {addEventListener(event, handler) { assert.equal(event, 'load'); load = handler; }},
        });
        assert.deepEqual(registrations, []);
        load();
        assert.deepEqual(registrations, ['/sw.js']);
    }
    vm.runInNewContext(script, {navigator: {}, window: {
        addEventListener() { assert.fail('Unsupported browsers must not register a worker'); },
    }});
});

test('security Cancel closes its own confirmation without intercepting form reset', () => {
    const blade = fs.readFileSync('resources/views/profile/security.blade.php', 'utf8');
    assert.equal((blade.match(/type="reset"[^>]*data-security-cancel/g) || []).length, 2);
    assert.ok(fs.readFileSync('resources/views/profile/edit.blade.php', 'utf8').includes("asset('js/profile.js')"));
    const panels = [{open: true}, {open: true}];
    const buttons = panels.map(panel => ({closest(selector) { assert.equal(selector, 'details'); return panel; },
        addEventListener(event, handler) { assert.equal(event, 'click'); this.click = handler; }}));
    vm.runInNewContext(fs.readFileSync('public/js/profile.js', 'utf8'), {document: {
        querySelectorAll(selector) { assert.equal(selector, '[data-security-cancel]'); return buttons; },
    }});
    assert.equal(buttons[0].click(), undefined);
    assert.deepEqual(panels.map(panel => panel.open), [false, true]);
    buttons[1].click();
    assert.deepEqual(panels.map(panel => panel.open), [false, false]);
});

test('facility barangay browsing submits once and still works after an AJAX page replacement', () => {
    const handlers = [];
    const blade = fs.readFileSync('resources/views/facilities/index.blade.php', 'utf8');
    assert.match(blade, /id="browse-barangay"[^>]*data-browse-barangay/);
    vm.runInNewContext(fs.readFileSync('public/js/facility-management.js', 'utf8'), {
        WeakMap, WeakSet, document: {
            addEventListener(event, handler) { if (event === 'change') handlers.push(handler); },
        },
    });
    let submitted = 0;
    for (const value of ['Taft', 'Washington']) {
        const form = {submit() { submitted++; }};
        const target = {form, value, closest() { return null; },
            matches(selector) { return selector === '[data-browse-barangay]'; }};
        handlers.forEach(handler => handler({target}));
        assert.equal(target.value, value);
    }
    assert.equal(submitted, 2);
});
