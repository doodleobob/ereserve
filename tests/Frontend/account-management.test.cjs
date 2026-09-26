const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup() {
    const button = () => ({addEventListener(_, callback) { this.click = callback; }});
    const cancel = button();
    const confirm = button();
    const dialog = {
        open: false,
        querySelector: selector => selector === '[data-account-cancel]' ? cancel : confirm,
        showModal() { this.open = true; },
        close() { this.open = false; this.onClose(); },
        addEventListener(_, callback) { this.onClose = callback; },
    };
    const forms = [1, 2].map(id => ({
        id, submitted: 0, button: {disabled: true},
        querySelector() { return this.button; },
        addEventListener(_, callback) { this.onSubmit = callback; },
        submit() { this.onSubmit({preventDefault() {}}); },
    }));
    vm.runInNewContext(fs.readFileSync('public/js/account-management.js', 'utf8'), {
        document: {getElementById: () => dialog, querySelectorAll: () => forms},
        HTMLFormElement: {prototype: {submit() { this.submitted++; }}},
    });
    return {forms, dialog, cancel, confirm};
}

test('deactivation waits for confirmation and submits only the selected account', () => {
    const {forms, dialog, confirm} = setup();
    assert.equal(forms[1].button.disabled, false);
    forms[1].submit();
    assert.equal(dialog.open, true);
    assert.equal(forms[1].submitted, 0);
    confirm.click();
    assert.equal(forms[1].submitted, 1);
    assert.equal(forms[0].submitted, 0);
    assert.equal(dialog.open, false);
    confirm.click();
    assert.equal(forms[1].submitted, 1);
});

test('Cancel and Escape-close clear the pending account without submitting', () => {
    const {forms, dialog, cancel, confirm} = setup();
    forms[0].submit();
    cancel.click();
    confirm.click();
    assert.equal(forms[0].submitted, 0);
    forms[1].submit();
    dialog.close();
    confirm.click();
    assert.equal(forms[1].submitted, 0);
});
