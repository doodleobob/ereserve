document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) return;

    button.hidden = false;
    button.addEventListener('click', () => {
        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        button.setAttribute('aria-label', showPassword ? button.dataset.hideLabel : button.dataset.showLabel);
        button.classList.toggle('is-visible', showPassword);
        input.focus({ preventScroll: true });
    });
});
