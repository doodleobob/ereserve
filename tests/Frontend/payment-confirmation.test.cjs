const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(value = '1500.00', valid = true, fetch = async () => {throw Error('Unexpected fetch');}) {
    const amount = {value: ''};
    const label = {};
    const facility = {};
    const cancel = {addEventListener: (_, callback) => { cancel.click = callback; }};
    const confirm = {disabled:false};
    const availability = {hidden:true,replaceChildren(){this.innerHTML='';this.textContent='';},setAttribute(){},removeAttribute(){}};
    const form = {removeAttribute: name => { delete form[name]; },querySelector:()=>confirm,addEventListener:(_,fn)=>{form.submit=fn;}};
    const input = {value};
    const button = {
        dataset: {facility: 'Covered Court', acceptUrl: '/reservations/42/accept'},
        form: {reportValidity: () => valid, querySelector: () => input},
        addEventListener: (_, callback) => { button.click = callback; },
        focus: () => { button.focused = true; },
    };
    const fields = {'[data-payment-confirmation-form]': form, '[data-confirmed-amount]': amount, '[data-confirmed-amount-label]': label, '[data-confirmed-facility]': facility, '[data-cancel-payment]': cancel,'[data-acceptance-availability]':availability};
    const dialog = {
        open: false, querySelector: selector => fields[selector],
        showModal: () => { dialog.open = true; },
        close: () => { dialog.open = false; dialog.onClose(); },
        addEventListener: (_, callback) => { dialog.onClose = callback; },
    };
    vm.runInNewContext(fs.readFileSync('public/js/payment-confirmation.js', 'utf8'), {
        document: {querySelector: () => dialog, querySelectorAll: () => [button]}, Intl, AbortController, fetch,
    });
    return {button, amount, label, facility, form, input, dialog, cancel, confirm, availability};
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

test('legacy reservations can open a blank confirmation for the admin to enter a real amount', () => {
    const ui = setup('', true);
    ui.button.dataset.allowEmpty = 'true';
    ui.button.click();
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.amount.value, '');
    assert.equal(ui.label.textContent, 'the amount entered below');
});

const flush=async()=>{for(let i=0;i<10;i++)await Promise.resolve();};
const result=(canAccept=true)=>({ok:true,redirected:false,json:async()=>({html:'<section>Fresh quantities</section>',summary:{can_accept:canAccept,equipment_name:'Chairs',schedule_label:'Tomorrow'}})});

test('equipment acceptance waits for fresh stock and blocks failed or insufficient reviews',async()=>{
 let finish,requests=0;
 const ui=setup('200',true,(_,options)=>{requests++;assert.equal(options.cache,'no-store');return new Promise(resolve=>finish=resolve);});
 ui.button.dataset.equipmentAvailabilityUrl='/reservations/42/equipment-availability';ui.button.click();
 assert.ok(ui.confirm.disabled);assert.equal(ui.availability.hidden,false);
 let prevented=false;ui.form.submit({preventDefault(){prevented=true;},stopImmediatePropagation(){}});assert.ok(prevented);
 finish(result(false));await flush();assert.ok(ui.confirm.disabled);assert.match(ui.availability.innerHTML,/Fresh quantities/);
 ui.dialog.close();ui.button.click();finish(result(true));await flush();assert.equal(ui.confirm.disabled,false);assert.equal(ui.facility.textContent,'Chairs');
 ui.dialog.close();ui.button.click();finish({ok:false});await flush();assert.ok(ui.confirm.disabled);assert.match(ui.availability.textContent,/Unable to check/);assert.equal(requests,3);
});

test('late equipment responses cannot overwrite a reopened facility confirmation',async()=>{
 let finish;const ui=setup('200',true,()=>new Promise(resolve=>finish=resolve));
 ui.button.dataset.equipmentAvailabilityUrl='/reservations/42/equipment-availability';ui.button.click();ui.dialog.close();
 delete ui.button.dataset.equipmentAvailabilityUrl;ui.button.click();
 finish(result());await flush();assert.equal(ui.availability.hidden,true);assert.equal(ui.confirm.disabled,false);assert.equal(ui.facility.textContent,'Covered Court');
});
