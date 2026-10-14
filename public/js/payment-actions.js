(() => {
    if (!document.querySelector('[data-payment-table]')) return;
    const selector = '[data-payment-table] .reservation-table-actions';
    let active;
    const close = (focus = false) => {
        if (!active) return;
        const summary = active.querySelector('summary');
        active.open = false;
        summary.setAttribute('aria-expanded', 'false');
        active = null;
        if (focus) summary.focus({preventScroll: true});
    };
    const open = details => {
        close();
        active = details;
        details.open = true;
        const summary = details.querySelector('summary');
        summary.setAttribute('aria-expanded', 'true');
        const menu = details.querySelector('.reservation-table-menu');
        const anchor = summary.getBoundingClientRect(), bounds = menu.getBoundingClientRect();
        const gap = 6, edge = 8;
        const left = Math.max(edge, Math.min(anchor.right - bounds.width, window.innerWidth - bounds.width - edge));
        const top = anchor.bottom + gap + bounds.height <= window.innerHeight - edge
            ? anchor.bottom + gap : Math.max(edge, anchor.top - bounds.height - gap);
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    };
    // Capture payment menu choices so modal focus returns to the visible dots,
    // not to an action hidden inside the closed dropdown.
    document.addEventListener('click', event => {
        const details = event.target.closest(selector);
        if (!details) { close(); return; }
        const summary = event.target.closest('summary');
        const action = event.target.closest('[data-reservation-open]');
        if (!summary && !action) return;
        event.preventDefault();
        event.stopPropagation();
        if (window.EReserveModal.processing) return;
        if (summary) {
            if (active === details) close();
            else open(details);
        } else {
            const trigger = details.querySelector('summary');
            close();
            window.EReserveModal.open(document.getElementById(action.dataset.reservationOpen), trigger);
        }
    }, true);
    document.addEventListener('keydown', event => {
        if (!active || !active.contains(event.target)) return;
        if (event.key === 'Escape') { event.preventDefault(); close(true); }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const buttons = [...active.querySelectorAll('.reservation-table-menu button')];
            const current = buttons.indexOf(document.activeElement);
            const next = current < 0 ? (event.key === 'ArrowDown' ? 0 : buttons.length - 1)
                : (current + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
            buttons[next]?.focus();
        }
    });
    document.addEventListener('focusin', event => { if (active && !active.contains(event.target)) close(); });
    document.addEventListener('scroll', event => {
        if (active && !active.querySelector('.reservation-table-menu').contains(event.target)) close();
    }, true);
    window.addEventListener('resize', () => close());
    document.addEventListener('reservations:updated', () => close());
})();
