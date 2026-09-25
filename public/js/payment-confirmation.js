(() => {
    const dialog = document.querySelector('#payment-confirmation');
    if (!dialog) return;
    const form = dialog.querySelector('[data-payment-confirmation-form]');
    const amount = dialog.querySelector('[data-confirmed-amount]');
    const label = dialog.querySelector('[data-confirmed-amount-label]');
    const facility = dialog.querySelector('[data-confirmed-facility]');
    let trigger;
    document.querySelectorAll('[data-confirm-payment]').forEach(button => {
        button.addEventListener('click', () => {
            if (!button.form.reportValidity()) return;
            const value = button.form.querySelector('[name="total_payment"]').value;
            if (!/^\d+(\.\d{1,2})?$/.test(value)) return;
            trigger = button;
            amount.value = value;
            label.textContent = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(Number(value));
            facility.textContent = button.dataset.facility;
            form.action = button.dataset.acceptUrl;
            dialog.showModal();
        });
    });
    dialog.querySelector('[data-cancel-payment]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        amount.value = '';
        form.removeAttribute('action');
        trigger?.focus();
    });
})();
