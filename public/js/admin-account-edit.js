(() => {
    const initialize = () => {
        const form = document.querySelector('[data-admin-edit-form]');
        const dialog = document.getElementById('admin-edit-confirmation');
        const confirm = dialog?.querySelector('[data-admin-edit-confirm]');
        if (!form || !dialog || !confirm) return;

        form.addEventListener('submit', event => {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }
            event.preventDefault();
            window.EReserveModal?.open(dialog, form.querySelector('[type="submit"]'));
        });

        confirm.addEventListener('click', () => {
            if (!form.reportValidity()) return;
            form.dataset.confirmed = 'true';
            form.requestSubmit();
        });
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
