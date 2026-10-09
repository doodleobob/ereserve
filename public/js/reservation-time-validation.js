(() => {
    const loadedAt = Date.now();
    const serverNow = Number(document.currentScript?.dataset.serverNow) || loadedAt;
    const now = () => serverNow + Date.now() - loadedAt;
    const format = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    });
    const parts = timestamp => Object.fromEntries(format.formatToParts(timestamp).map(part => [part.type, part.value]));
    const dateOf = value => `${value.year}-${value.month}-${value.day}`;
    const states = new WeakMap();
    let sequence = 0;
    const validate = form => {
        if (form.closest('dialog')?.hasAttribute('aria-busy')) return true;
        const date = form.elements.namedItem('reservation_date');
        const start = form.elements.namedItem('start_time');
        const end = form.elements.namedItem('end_time');
        if (!date || !start || !end) return true;
        let state = states.get(form);
        if (!state) {
            const error = document.createElement('p');
            error.className = 'form-error';
            error.setAttribute('role', 'alert');
            error.id = 'future-reservation-error-' + ++sequence;
            error.hidden = true;
            // Keep field labels and the existing server-error handlers intact.
            (start.type === 'hidden' ? form.querySelector('.selected-slot-summary') || start : start.closest('label') || start).after(error);
            for (const field of [date, start, end]) {
                field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
            }
            state = {error, blocked: new Set()}; states.set(form, state);
        }
        const scheduling = !form.elements.namedItem('action') || form.elements.namedItem('action').value === 'reschedule';
        const timestamp = now(), current = parts(timestamp), next = parts(Math.floor(timestamp / 60000) * 60000 + 60000);
        const today = dateOf(current), nextDate = dateOf(next);
        if (date.type === 'date') date.min = scheduling ? nextDate : '';
        if (scheduling && date.value === today && nextDate === today) start.min = `${next.hour}:${next.minute}`;
        else start.removeAttribute('min');
        let message = '', invalid;
        const selected = date.value && start.value ? Date.parse(`${date.value}T${start.value}:00+08:00`) : NaN;
        if (scheduling && date.value && date.value < today) {
            message = 'The reservation date cannot be in the past.'; invalid = date;
        } else if (scheduling && ((Number.isFinite(selected) && selected <= timestamp) || date.value === today && nextDate !== today)) {
            message = 'The selected reservation start time must be in the future.'; invalid = start;
        } else if (scheduling && start.value && end.value && start.value === end.value) {
            message = 'End Time must be different from Start Time. An earlier End Time ends the next day.'; invalid = end;
        }
        for (const field of [date, start, end]) field.setCustomValidity(field === invalid ? message : '');
        state.error.textContent = message;
        state.error.hidden = !message;
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(button => {
            if (message && !button.disabled) { button.disabled = true; state.blocked.add(button); }
            else if (!message && state.blocked.has(button)) { button.disabled = false; state.blocked.delete(button); }
        });
        return !message;
    };
    const refresh = () => document.querySelectorAll('form[data-future-reservation]').forEach(validate);
    const changed = event => {
        const form = event.target.closest('form[data-future-reservation]');
        if (form) validate(form);
    };
    document.addEventListener('input', changed);
    document.addEventListener('change', changed);
    document.addEventListener('click', event => {
        const submit = event.target.closest('button[type="submit"], input[type="submit"]');
        const form = submit?.form;
        if (form?.matches('[data-future-reservation]') && !validate(form)) {
            event.preventDefault(); event.stopImmediatePropagation(); form.reportValidity();
        }
    }, true);
    document.addEventListener('submit', event => {
        if (event.target.matches('[data-future-reservation]') && !validate(event.target)) {
            event.preventDefault(); event.stopImmediatePropagation(); event.target.reportValidity();
        }
    }, true);
    // Native validation can run before submit fires, especially after a form sits open.
    document.addEventListener('invalid', changed, true);
    document.addEventListener('reset', () => setTimeout(refresh, 0));
    document.addEventListener('click', () => setTimeout(refresh, 0));
    document.addEventListener('modals:updated', refresh);
    document.addEventListener('reservations:updated', refresh);
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('pageshow', refresh);
    setInterval(refresh, 1000);
    refresh();
})();
