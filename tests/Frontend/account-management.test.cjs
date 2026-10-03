const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup() {
    const handlers = {}, description = {}, confirm = {};
    const confirmationForm = {elements: {is_active: {value: ''}}};
    const dialog = {open: false, querySelector: selector => selector === 'form' ? confirmationForm : selector === '[data-account-confirm]' ? confirm : description};
    const details = {open: true};
    const forms = [0, 1].map(id => ({action: '/admin/residents/' + (id + 1) + '/status', dataset: {accountName: 'Resident ' + id}, elements: {is_active: {value: String(id)}}, button: {disabled: true}, matches: () => true, querySelector() { return this.button; }}));
    const modals = {processing: false, open(target) { target.open = true; }, close(target) { target.open = false; }};
    vm.runInNewContext(fs.readFileSync('public/js/account-management.js', 'utf8'), {
        window: {EReserveModal: modals},
        document: {querySelectorAll: () => forms.map(form => form.button), getElementById: id => id === 'account-confirmation' ? dialog : details, addEventListener: (event, handler) => handlers[event] = handler},
    });
    return {forms, dialog, details, confirmationForm, description, confirm, modals, submit: form => handlers.submit({target: form, preventDefault() {}})};
}

test('deactivation opens a confirmation for the selected account and closes stale details', () => {
    const ui = setup();
    assert.ok(ui.forms.every(form => !form.button.disabled));
    ui.submit(ui.forms[0]);
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.details.open, false);
    assert.equal(ui.confirmationForm.action, '/admin/residents/1/status');
    assert.equal(ui.confirmationForm.elements.is_active.value, '0');
    assert.match(ui.description.textContent, /Deactivate Resident 0/);
    assert.equal(ui.confirm.textContent, 'Deactivate');
});

test('activation uses the same confirmation with the selected existing status endpoint', () => {
    const ui = setup();
    ui.submit(ui.forms[1]);
    assert.equal(ui.confirmationForm.action, '/admin/residents/2/status');
    assert.equal(ui.confirmationForm.elements.is_active.value, '1');
    assert.match(ui.description.textContent, /Activate Resident 1/);
    assert.equal(ui.confirm.textContent, 'Activate');
});

test('a processing modal cannot be replaced by another account action', () => {
    const ui = setup();
    ui.modals.processing = true;
    ui.submit(ui.forms[0]);
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.confirmationForm.action, undefined);
});
