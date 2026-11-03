(() => {
    document.querySelectorAll('[data-charge-reason]').forEach(select => {
        const form = select.closest('form');
        const otherGroup = form?.querySelector('[data-charge-reason-other]');
        const otherInput = otherGroup?.querySelector('input[name="reason_other"]');
        if (!otherGroup || !otherInput) return;

        const sync = () => {
            const isOther = select.value === 'Other';
            otherGroup.hidden = !isOther;
            otherInput.required = isOther;
            if (!isOther) otherInput.value = '';
        };

        select.addEventListener('change', sync);
        sync();
    });

    const chargeForm = document.querySelector('[data-equipment-charge-form]');
    chargeForm?.addEventListener('submit', event => {
        if (chargeForm.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        chargeForm.dataset.submitting = 'true';
        const dialog = chargeForm.closest('dialog');
        const button = chargeForm.querySelector('button[type="submit"]');
        if (dialog) dialog.setAttribute('aria-busy', 'true');
        if (button) {
            button.disabled = true;
            button.textContent = 'Saving…';
        }
    });

    const autoOpen = document.querySelector('[data-charge-auto-open]');
    if (autoOpen) window.EReserveModal?.open(autoOpen.closest('dialog'), document.querySelector('[data-modal-open="record-equipment-charge"]'));
})();
