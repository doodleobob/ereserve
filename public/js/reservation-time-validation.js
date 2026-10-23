(() => {
    const loadedAt = Date.now();
    const serverNow = Number(document.currentScript?.dataset.serverNow) || loadedAt;
    const now = () => serverNow + Date.now() - loadedAt;
    const format = new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'});
    const parts = timestamp => Object.fromEntries(format.formatToParts(timestamp).map(part => [part.type, part.value]));
    const dateOf = value => `${value.year}-${value.month}-${value.day}`;
    const states = new WeakMap();
    const money = value => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(value);
    const scheduleFormat = new Intl.DateTimeFormat('en-PH', {timeZone: 'Asia/Manila', year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit'});
    const validate = form => {
        if (form.closest('dialog')?.hasAttribute('aria-busy')) return true;
        const field = name => form.elements.namedItem(name);
        const date = field('reservation_date') || field('date'), start = field('start_time'), end = field('end_time');
        if (!date || !start || !end) return true;
        const nextDay = field('end_next_day')?.value === '1';
        const unified = form.dataset.unifiedBooking !== undefined;
        const quantity = field('quantity');
        let state = states.get(form);
        if (!state) {
            state = {blocked: new Set(), key: null, result: null, controller: null}; states.set(form, state);
        }
        const scheduling = !field('action') || field('action').value === 'reschedule';
        const original = form.dataset || {};
        const unchanged = date.value === original.originalDate && start.value === original.originalStart && end.value === original.originalEnd
            && nextDay === (original.originalEnd < original.originalStart)
            && (!original.originalResource || field('facility_id')?.value === original.originalResource);
        const timestamp = now(), today = dateOf(parts(timestamp));
        const requiresFuture = !unchanged || original.pendingReservationAction !== undefined;
        const next = parts(Math.floor(timestamp / 3600000) * 3600000 + 3600000), nextDate = dateOf(next);
        if (date.type === 'date') date.min = scheduling && requiresFuture ? (unchanged ? today : nextDate) : '';
        const selected = date.value && start.value ? Date.parse(`${date.value}T${start.value}:00+08:00`) : NaN;
        let message = '', invalid;
        if (scheduling && requiresFuture) {
            if (date.value && date.value < today) { message = 'The reservation date cannot be in the past.'; invalid = date; }
            else if (Number.isFinite(selected) && selected <= timestamp) { message = 'The selected reservation start time must be in the future.'; invalid = start; }
            else if (!unchanged && start.value && !/^\d{2}:00$/.test(start.value)) { message = 'Please select a whole-hour start time.'; invalid = start; }
            else if (!unchanged && end.value && !/^\d{2}:00$/.test(end.value)) { message = 'Please select a whole-hour end time.'; invalid = end; }
            else if (!unchanged && start.value && end.value && (nextDay ? end.value >= start.value : end.value <= start.value)) {
                message = nextDay ? 'For an overnight booking, choose an earlier end time on the following day. Start and end times must be different.'
                    : 'End time must be later than start time. Select Next day for an overnight booking.'; invalid = end;
            }
        }
        if (unified && !message) {
            if (!date.value) { message = 'Please select your reservation date.'; invalid = date; }
            else if (!start.value || !end.value || !Number.isFinite(selected)) { message = 'Please choose a valid start and end time.'; invalid = !start.value ? start : end; }
            else if (quantity && (!/^[1-9]\d*$/.test(quantity.value) || Number(quantity.value) > Number(quantity.max))) {
                message = `Please enter a positive whole quantity, up to ${quantity.max} units.`; invalid = quantity;
            }
        }
        const summary = form.querySelector('[data-hourly-summary]');
        const endStamp = date.value && end.value ? Date.parse(`${date.value}T${end.value}:00+08:00`) + (nextDay ? 86400000 : 0) : NaN;
        const hours = (endStamp - selected) / 3600000;
        if (summary) summary.textContent = !message && hours > 0 ? `Duration: ${Number(hours.toFixed(2))} ${hours === 1 ? 'hour' : 'hours'}${nextDay ? ' · Ends next day' : ''}` : 'Select a valid start and end time to view the duration.';
        // Options and estimates come from the authoritative interval and pricing services.
        const params = new URLSearchParams({reservation_date: date.value, end_next_day: nextDay ? '1' : '0'});
        for (const name of ['start_time', 'end_time', 'quantity']) if (field(name)?.value) params.set(name, field(name).value);
        if (original.reservationId) params.set('reservation_id', original.reservationId);
        const key = `${params}|${Math.floor(timestamp / 60000)}`;
        if (original.hourlyAvailability && date.value && !message && scheduling && state.key !== key) {
            if (unified) clearTimeout(state.timer);
            state.controller?.abort(); state.controller = new AbortController(); state.key = key; state.result = null;
            const controller = state.controller;
            const request = () => {
                const timeout = unified ? setTimeout(() => {
                    if (state.controller !== controller || state.result) return;
                    state.result = {checkFailed: true}; controller.abort(); validate(form);
                }, 12000) : null;
                return fetch(original.hourlyAvailability + '?' + params, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: controller.signal})
                .then(async response => {
                    const data = await response.json(); if (state.key !== key || state.controller !== controller || controller.signal.aborted) return;
                    state.result = response.ok ? data : response.status === 422
                        ? {errors: data.errors, message: Object.values(data.errors || {}).flat()[0] || 'The schedule could not be checked.', available: false}
                        : {checkFailed: true};
                    validate(form);
                }).catch(error => {
                    if (error.name !== 'AbortError' && state.key === key && state.controller === controller) { state.result = {checkFailed: true}; validate(form); }
                }).finally(() => { if (timeout) clearTimeout(timeout); });
            };
            if (unified) state.timer = setTimeout(request, 180);
            else request();
        } else if (message || !date.value) { if (unified) clearTimeout(state.timer); state.controller?.abort(); state.key = null; state.result = null; }
        const result = state.key === key ? state.result : null;
        for (const option of Array.from(start.options || [])) option.disabled = !!option.value && !unchanged && (!date.value
            || Date.parse(`${date.value}T${option.value}:00+08:00`) <= timestamp || option.hasAttribute?.('data-legacy-time')
            || (result?.start_options && !result.start_options.includes(option.value)));
        for (const option of Array.from(end.options || [])) option.disabled = !!option.value && !unchanged && (!start.value
            || (nextDay ? option.value >= start.value : option.value <= start.value) || option.hasAttribute?.('data-legacy-time')
            || (result?.end_options && !result.end_options.includes(option.value)));
        if (summary && result && !message && !unified) {
            if (result.estimated_total != null) summary.textContent += ` · Hourly Rate: ${money(result.hourly_rate)} · Estimated Total: ${money(result.estimated_total)}`;
            if (result.end_date && nextDay) summary.textContent += ` (${result.end_date})`;
            if (result.checkFailed) summary.textContent += ' · Availability could not be checked. Submission will recheck the schedule.';
        }
        const quantityOutput = form.querySelector('[data-available-quantity]');
        if (quantityOutput) quantityOutput.textContent = result?.available_quantity != null ? `Available Quantity for selected time: ${result.available_quantity} units` : 'Select a date and time to view Available Quantity.';
        if (quantityOutput && result?.buffer_minutes && result?.effective_end) quantityOutput.textContent += ` · Includes a ${result.buffer_minutes}-minute buffer. Allocation ends ${new Date(result.effective_end).toLocaleString('en-PH', {timeZone: 'Asia/Manila'})}.`;
        let presentationErrors = message ? {[invalid?.name || '_form']: message} : {};
        if (!message && result?.available === false && start.value && end.value) {
            message = result.message || 'The selected hours are no longer available.';
            presentationErrors = Object.keys(result.errors || {}).length ? result.errors : {_form: message};
            invalid = Object.keys(presentationErrors).map(name => field(name)).find(control => control && control.type !== 'hidden');
        }
        const pending = unified && !message && !result;
        const failed = unified && !message && result && (result.checkFailed || typeof result.available !== 'boolean');
        if (unified) {
            const write = (name, text) => { const node = form.querySelector(`[data-booking-${name}]`); if (node && node.textContent !== text) node.textContent = text; };
            write('start', Number.isFinite(selected) ? scheduleFormat.format(selected) : 'Select a schedule');
            write('end', Number.isFinite(endStamp) ? scheduleFormat.format(endStamp) : 'Select a schedule');
            write('duration', hours > 0 ? `${hours} ${hours === 1 ? 'hour' : 'hours'}` : '—');
            write('quantity', quantity?.value || '—');
            write('total', !message && result?.estimated_total != null ? money(result.estimated_total) : '—');
            if (result?.hourly_rate != null) write('rate', `${money(result.hourly_rate)} / hour`);
            write('status', message ? 'Review the highlighted fields or form message above.' : (pending ? 'Checking availability…' : failed ? 'Availability could not be checked. Please retry.' : 'Available for the selected period. Final availability is checked on submission.'));
            form.querySelector('[data-booking-retry]').hidden = !failed;
            if (pending || failed) {
                if (quantityOutput) quantityOutput.textContent = pending ? 'Checking available quantity…' : 'Available quantity is unknown. Please retry.';
            }
        }
        for (const control of [date, start, end, ...(quantity ? [quantity] : [])]) control.setCustomValidity(control === invalid ? message : '');
        window.EReserveValidation.set(form, 'schedule', presentationErrors);
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(button => {
            if (unified) button.disabled = !!(message || pending || failed || state.submitting || result?.available !== true);
            else if (message && !button.disabled) { button.disabled = true; state.blocked.add(button); }
            else if (!message && state.blocked.has(button)) { button.disabled = false; state.blocked.delete(button); }
        });
        return !message && !pending && !failed && !(unified && state.submitting);
    };
    const refresh = () => document.querySelectorAll('form[data-future-reservation]').forEach(validate);
    const changed = event => { const form = event.target.closest('form[data-future-reservation]'); if (form) validate(form); };
    document.addEventListener('input', changed); document.addEventListener('change', changed);
    document.addEventListener('click', event => {
        const retry = event.target.closest('[data-booking-retry]');
        if (retry) {
            const form = retry.closest('form'), state = states.get(form);
            if (state) { state.key = null; validate(form); }
        }
        const form = event.target.closest('button[type="submit"], input[type="submit"]')?.form;
        if (form?.matches('[data-future-reservation]') && !validate(form)) { event.preventDefault(); event.stopImmediatePropagation(); form.reportValidity(); }
    }, true);
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches('[data-future-reservation]')) return;
        if (!validate(form)) { event.preventDefault(); event.stopImmediatePropagation(); form.reportValidity(); }
        else if (form.dataset.unifiedBooking !== undefined) {
            if (!form.reportValidity()) { event.preventDefault(); return; }
            states.get(form).submitting = true;
            form.querySelector('button[type="submit"]').disabled = true;
        }
    }, true);
    document.addEventListener('invalid', changed, true);
    document.addEventListener('reset', () => setTimeout(refresh, 0));
    document.addEventListener('click', () => setTimeout(refresh, 0));
    for (const name of ['modals:updated', 'reservations:updated', 'visibilitychange']) document.addEventListener(name, refresh);
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-unified-booking]').forEach(form => {
            const state = states.get(form);
            if (state) { state.submitting = false; state.key = null; }
        });
        refresh();
    }); setInterval(refresh, 1000); refresh();
})();
