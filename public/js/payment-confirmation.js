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
    let trigger;
    document.querySelectorAll('[data-confirm-payment]').forEach(button => {
        button.addEventListener('click', () => {
            if (!button.form.reportValidity()) return;
            const value = button.form.querySelector('[name="total_payment"]').value;
            if (!/^\d+(\.\d{1,2})?$/.test(value) && !(value===''&&button.dataset.allowEmpty==='true')) return;
            trigger = button;
            amount.value = value;
            label.textContent = value==='' ? 'the amount entered below' : new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(Number(value));
            facility.textContent = button.dataset.facility;
            if(resident)resident.textContent='Resident: '+button.dataset.resident;
            if(schedule)schedule.textContent='Schedule: '+button.dataset.schedule;
            form.action = button.dataset.acceptUrl;
            if (globalThis.EReserveModal) globalThis.EReserveModal.open(dialog, button);
            else dialog.showModal();
        });
    });
    amount.addEventListener?.('input',()=>{label.textContent=amount.value===''?'the amount entered below':new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(amount.value));});
    dialog.querySelector('[data-cancel-payment]').addEventListener('click', () => {
        if (globalThis.EReserveModal) globalThis.EReserveModal.close(dialog);
        else dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (dialog.open) return;
        amount.value = '';
        form.removeAttribute('action');
        trigger?.focus();
    });
    };
    initialize();
    document.addEventListener?.('reservations:updated',initialize);
})();
