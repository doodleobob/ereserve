(() => {
    const initialize = () => {
    const dialog = document.querySelector('#payment-confirmation');
    if (!dialog) return;
    if (dialog.dataset?.initialized) return;
    if (dialog.dataset) dialog.dataset.initialized = 'true';
    const form = dialog.querySelector('[data-payment-confirmation-form]');
    const amount = dialog.querySelector('[data-confirmed-amount]');
    const label = dialog.querySelector('[data-confirmed-amount-label]');
    const facility = dialog.querySelector('[data-confirmed-facility]');
    const resident = dialog.querySelector('[data-confirmed-resident]');
    const schedule = dialog.querySelector('[data-confirmed-schedule]');
    const availability = dialog.querySelector('[data-acceptance-availability]');
    const confirm = form.querySelector?.('button[type="submit"]');
    let availabilityRequest, version = 0, availabilityReady = true;
    const loadAvailability = async url => {
        availabilityRequest?.abort();
        const current = ++version;
        availabilityReady = !url;
        if (confirm) confirm.disabled = !!url;
        if (!availability) return;
        availability.hidden = !url;
        availability.replaceChildren();
        if (!url) return;
        availability.textContent = 'Checking equipment availability…';
        availability.setAttribute('aria-busy', 'true');
        availabilityRequest = new AbortController();
        try {
            const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}, signal:availabilityRequest.signal});
            if (!response.ok || response.redirected) throw Error('Unable to check equipment availability. Close and reopen this confirmation to retry.');
            const data = await response.json();
            if (current !== version || !dialog.open) return;
            if (typeof data.html !== 'string' || typeof data.summary?.can_accept !== 'boolean') throw Error('The availability response is incomplete. Close and reopen to retry.');
            // HTML is rendered and escaped by the authorized same-origin Blade endpoint.
            availability.innerHTML = data.html;
            facility.textContent = data.summary.equipment_name;
            if (schedule) schedule.textContent = 'Schedule: ' + data.summary.schedule_label;
            availabilityReady = data.summary.can_accept;
            if (confirm) confirm.disabled = !availabilityReady;
        } catch (error) {
            if (current === version && error.name !== 'AbortError') availability.textContent = error.message;
        } finally {
            if (current === version) availability.removeAttribute('aria-busy');
        }
    };
    form.addEventListener?.('submit', event => {
        if (!availabilityReady) { event.preventDefault(); event.stopImmediatePropagation(); }
    });
    let trigger;
    document.querySelectorAll('[data-confirm-payment]').forEach(button => {
        button.addEventListener('click', () => {
            if (globalThis.EReserveModal?.processing) return;
            if (!button.form.reportValidity()) return;
            const value = button.form.querySelector('[name="total_payment"]').value;
            if (!/^\d+(\.\d{1,2})?$/.test(value) && !(value===''&&button.dataset.allowEmpty==='true')) return;
            trigger = button.closest?.('[data-table-actions]')?.querySelector('summary') || button;
            amount.value = value;
            label.textContent = value==='' ? 'the amount entered below' : new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(Number(value));
            facility.textContent = button.dataset.facility;
            if(resident)resident.textContent='Resident: '+button.dataset.resident;
            if(schedule)schedule.textContent='Schedule: '+button.dataset.schedule;
            form.action = button.dataset.acceptUrl;
            if (globalThis.EReserveModal) globalThis.EReserveModal.open(dialog, button);
            else dialog.showModal();
            loadAvailability(button.dataset.equipmentAvailabilityUrl);
        });
    });
    amount.addEventListener?.('input',()=>{label.textContent=amount.value===''?'the amount entered below':new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(amount.value));});
    dialog.querySelector('[data-cancel-payment]').addEventListener('click', () => {
        if (globalThis.EReserveModal) globalThis.EReserveModal.close(dialog);
        else dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (dialog.open) return;
        availabilityRequest?.abort(); version++;
        amount.value = '';
        form.removeAttribute('action');
        trigger?.focus();
    });
    document.querySelectorAll('[data-reservation-open]').forEach(button => {
        const details = document.getElementById?.(button.dataset.reservationOpen);
        const panel = details?.querySelector('[data-equipment-availability-url]');
        if (!panel) return;
        let request, sequence = 0;
        details.addEventListener('close', () => { request?.abort(); sequence++; });
        button.addEventListener('click', async () => {
            if (globalThis.EReserveModal?.processing) return;
            request?.abort(); request = new AbortController(); const current = ++sequence;
            panel.textContent = 'Checking equipment availability…'; panel.setAttribute('aria-busy', 'true');
            try {
                const response = await fetch(panel.dataset.equipmentAvailabilityUrl, {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:request.signal});
                if (!response.ok || response.redirected) throw Error('Unable to load availability. Close and reopen these details to retry.');
                const data = await response.json();
                if (typeof data.html !== 'string') throw Error('The availability response is incomplete. Close and reopen to retry.');
                if (current === sequence) panel.innerHTML = data.html;
            } catch(error) {
                if (current === sequence && error.name !== 'AbortError') panel.textContent = error.message;
            } finally { if (current === sequence) panel.removeAttribute('aria-busy'); }
        });
    });
    };
    initialize();
    document.addEventListener?.('reservations:updated',initialize);
})();
