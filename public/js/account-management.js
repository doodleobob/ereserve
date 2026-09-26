(() => {
    const dialog = document.getElementById('account-confirmation');
    let pendingForm = null;
    document.querySelectorAll('[data-deactivate-account]').forEach(form => {
        form.querySelector('button[type="submit"]').disabled = false;
        form.addEventListener('submit', event => {
            event.preventDefault();
            pendingForm = form;
            dialog.showModal();
        });
    });
    dialog.querySelector('[data-account-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { pendingForm = null; });
    dialog.querySelector('[data-account-confirm]').addEventListener('click', () => {
        if (pendingForm) {
            HTMLFormElement.prototype.submit.call(pendingForm);
            dialog.close();
        }
    });
})();
