const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(value = '1500.00', valid = true) {
    const amount = {value: ''};
    const label = {};
    const facility = {};
    const cancel = {addEventListener: (_, callback) => { cancel.click = callback; }};
    const form = {removeAttribute: name => { delete form[name]; }};
    const input = {value};
    const button = {
        dataset: {facility: 'Covered Court', acceptUrl: '/reservations/42/accept'},
        form: {reportValidity: () => valid, querySelector: () => input},
        addEventListener: (_, callback) => { button.click = callback; },
        focus: () => { button.focused = true; },
    };
    const fields = {'[data-payment-confirmation-form]': form, '[data-confirmed-amount]': amount, '[data-confirmed-amount-label]': label, '[data-confirmed-facility]': facility, '[data-cancel-payment]': cancel};
    const dialog = {
        open: false, querySelector: selector => fields[selector],
        showModal: () => { dialog.open = true; },
        close: () => { dialog.open = false; dialog.onClose(); },
        addEventListener: (_, callback) => { dialog.onClose = callback; },
    };
    vm.runInNewContext(fs.readFileSync('public/js/payment-confirmation.js', 'utf8'), {
        document: {querySelector: () => dialog, querySelectorAll: () => [button]}, Intl,
    });
    return {button, amount, label, facility, form, input, dialog, cancel};
}

test('Accept opens a confirmation with the edited amount and correct reservation', () => {
    const ui = setup();
    ui.input.value = '1400.00';
    ui.button.click();
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.label.textContent, '₱1,400.00');
    assert.equal(ui.amount.value, '1400.00');
    assert.equal(ui.facility.textContent, 'Covered Court');
    assert.equal(ui.form.action, '/reservations/42/accept');
    // The confirmation submits the exact amount displayed, even if the source changes.
    ui.input.value = '1500.00';
    assert.equal(ui.amount.value, '1400.00');
});

test('Cancel and Escape-close clear the pending confirmation without submitting', () => {
    const ui = setup();
    ui.button.click();
    ui.cancel.click();
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.amount.value, '');
    assert.equal(ui.form.action, undefined);
    assert.equal(ui.button.focused, true);
    ui.input.value = '1250.00';
    ui.button.click();
    assert.equal(ui.label.textContent, '₱1,250.00');
    ui.dialog.close();
    assert.equal(ui.amount.value, '');
});

test('invalid amounts cannot open confirmation, while zero can', () => {
    for (const [value, valid] of [['', false], ['-1', false], ['1.001', false], ['1e3', true]]) {
        const ui = setup(value, valid);
        ui.button.click();
        assert.equal(ui.dialog.open, false);
    }
    const ui = setup('0.00');
    ui.button.click();
    assert.equal(ui.label.textContent, '₱0.00');
});
