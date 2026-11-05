document.querySelectorAll('[data-security-code-resend]').forEach((form) => {
    const button = form.querySelector('[data-resend-button]');
    const countdown = form.querySelector('[data-resend-countdown]');
    if (!button || !countdown) return;

    let seconds = Math.max(0, Number.parseInt(form.dataset.resendSeconds || '0', 10));
    let deadline = Date.now() + seconds * 1000;
    let submitting = false;
    let interval;

    const render = () => {
        seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        button.disabled = submitting || seconds > 0;

        if (submitting) {
            button.textContent = 'Sending…';
            countdown.textContent = 'Sending your verification code.';
        } else if (seconds > 0) {
            countdown.textContent = `Resend code in ${seconds} seconds.`;
        } else {
            countdown.textContent = '';
            if (interval) {
                window.clearInterval(interval);
                interval = undefined;
            }
        }
    };

    render();
    if (seconds > 0) interval = window.setInterval(render, 1000);

    form.addEventListener('submit', (event) => {
        if (submitting || seconds > 0) {
            event.preventDefault();
            return;
        }

        submitting = true;
        render();
    });
});
