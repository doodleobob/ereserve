const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(seconds) {
    let now = 1_000_000;
    let tick;
    const handlers = {};
    const button = {disabled: seconds > 0, textContent: 'Resend Code'};
    const countdown = {textContent: ''};
    const form = {
        dataset: {resendSeconds: String(seconds)},
        querySelector: selector => selector === '[data-resend-button]' ? button : countdown,
        addEventListener: (name, handler) => { handlers[name] = handler; },
    };
    class FakeDate extends Date {
        static now() { return now; }
    }
    const window = {
        setInterval: handler => { tick = handler; return 1; },
        clearInterval: () => { tick = null; },
    };
    const document = {querySelectorAll: () => [form]};
    vm.runInNewContext(fs.readFileSync('public/js/security-code-resend.js', 'utf8'), {document, window, Date: FakeDate});

    return {
        button,
        countdown,
        submit() {
            let prevented = false;
            handlers.submit({preventDefault: () => { prevented = true; }});
            return prevented;
        },
        advance(milliseconds) {
            now += milliseconds;
            tick?.();
        },
    };
}

test('resend countdown updates once per second and enables after sixty seconds', () => {
    const ui = setup(60);
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.countdown.textContent, 'Resend code in 60 seconds.');

    ui.advance(1000);
    assert.equal(ui.countdown.textContent, 'Resend code in 59 seconds.');
    ui.advance(59_000);
    assert.equal(ui.button.disabled, false);
    assert.equal(ui.countdown.textContent, '');
});

test('server-provided remaining seconds restore the countdown after a page refresh', () => {
    const ui = setup(37);
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.countdown.textContent, 'Resend code in 37 seconds.');
});

test('submitting while limited is blocked and repeated sends are disabled while processing', () => {
    const ui = setup(1);
    assert.equal(ui.submit(), true);
    ui.advance(1000);
    assert.equal(ui.submit(), false);
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.button.textContent, 'Sending…');
    assert.equal(ui.countdown.textContent, 'Sending your verification code.');
    assert.equal(ui.submit(), true);
});
