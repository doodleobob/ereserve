const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(valid = true) {
    const handlers = {}, clicks = {};
    const submitButton = {};
    const form = {
        dataset: {},
        reportValidity: () => valid,
        querySelector: () => submitButton,
        addEventListener: (name, handler) => handlers[name] = handler,
        requestSubmit() {
            let prevented = false;
            handlers.submit({preventDefault() { prevented = true; }});
            this.submitted = !prevented;
        },
    };
    const confirmButton = {addEventListener: (name, handler) => clicks[name] = handler};
    const dialog = {querySelector: selector => selector === '[data-admin-edit-confirm]' ? confirmButton : null};
    let opened = null;
    vm.runInNewContext(fs.readFileSync('public/js/admin-account-edit.js', 'utf8'), {
        window: {EReserveModal: {open: (...args) => opened = args}},
        document: {
            readyState: 'complete',
            querySelector: () => form,
            getElementById: () => dialog,
        },
    });
    return {form, dialog, clicks, submitButton, get opened() { return opened; }};
}

test('admin edits require the existing confirmation modal before submitting', () => {
    const ui = setup();
    // The script registers the form submit listener; dispatch it through requestSubmit.
    ui.form.requestSubmit();
    assert.deepEqual(ui.opened, [ui.dialog, ui.submitButton]);
    assert.equal(ui.form.submitted, false);
    ui.clicks.click();
    assert.equal(ui.form.submitted, true);
    assert.equal(ui.form.dataset.confirmed, undefined);
});

test('invalid admin edits cannot be confirmed and submitted', () => {
    const ui = setup(false);
    ui.clicks.click();
    assert.equal(ui.form.submitted, undefined);
    assert.equal(ui.form.dataset.confirmed, undefined);
});
