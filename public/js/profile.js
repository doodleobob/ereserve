document.querySelectorAll('[data-security-cancel]').forEach((button) => {
    button.addEventListener('click', () => {
        button.closest('details').open = false;
    });
});
