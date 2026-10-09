(() => {
    const trigger = document.querySelector('.mobile-menu-button');
    const drawer = document.getElementById('mobile-navigation');
    if (!trigger || !drawer || typeof drawer.showModal !== 'function') return;

    const mobile = window.matchMedia('(max-width: 900px)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const closeButton = drawer.querySelector('.mobile-drawer-close');
    let timer, locked = false, scrollY = 0, bodyStyles, restoreFocus = true;
    const bodyProperties = ['position', 'top', 'left', 'right', 'width'];

    const cleanup = () => {
        clearTimeout(timer);
        drawer.classList.remove('is-closing');
        trigger.setAttribute('aria-expanded', 'false');
        if (locked) {
            bodyProperties.forEach(property => {
                const [value, priority] = bodyStyles[property];
                if (value) document.body.style.setProperty(property, value, priority);
                else document.body.style.removeProperty(property);
            });
            document.documentElement.classList.remove('mobile-drawer-open');
            window.scrollTo({top: scrollY, behavior: 'instant'});
            locked = false;
        }
        if (restoreFocus && mobile.matches && !document.querySelector('dialog.ereserve-modal[open]')) trigger.focus({preventScroll: true});
    };
    const close = (immediate = false, focus = true) => {
        if (!drawer.open) return;
        restoreFocus = focus;
        const finish = () => { drawer.close(); cleanup(); };
        clearTimeout(timer);
        if (immediate || reducedMotion.matches) finish();
        else {
            drawer.classList.add('is-closing');
            timer = setTimeout(finish, 180);
        }
    };
    trigger.hidden = false;
    document.documentElement.classList.add('mobile-navigation-ready');
    trigger.addEventListener('click', () => {
        if (!mobile.matches || drawer.open || document.querySelector('dialog.ereserve-modal[open]')) return;
        // Opening the drawer also closes the existing notification panel through its own handler.
        scrollY = window.scrollY;
        bodyStyles = Object.fromEntries(bodyProperties.map(property => [property,
            [document.body.style.getPropertyValue(property), document.body.style.getPropertyPriority(property)]]));
        document.body.style.position = 'fixed';
        document.body.style.top = `-${scrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        locked = true;
        restoreFocus = true;
        document.documentElement.classList.add('mobile-drawer-open');
        drawer.showModal();
        drawer.querySelector('.mobile-drawer-scroll').scrollTop = 0;
        trigger.setAttribute('aria-expanded', 'true');
        closeButton.focus();
    });
    closeButton.addEventListener('click', () => close());
    drawer.addEventListener('cancel', event => { event.preventDefault(); close(); });
    drawer.addEventListener('close', () => { if (!drawer.open && locked) cleanup(); });
    let backdropPress = false;
    const outside = event => {
        const rect = drawer.getBoundingClientRect();
        return event.target === drawer && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom);
    };
    drawer.addEventListener('pointerdown', event => { backdropPress = outside(event); });
    drawer.addEventListener('click', event => {
        if (backdropPress && outside(event)) close();
        backdropPress = false;
        if (event.target.closest('a[href]')) close(true);
    });
    drawer.addEventListener('submit', () => close(true));
    // Native modal dialogs make the background inert; also wrap Tab explicitly.
    drawer.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const controls = [...drawer.querySelectorAll('button:not(:disabled), a[href]')].filter(control => control.getClientRects().length);
        const first = controls[0], last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    mobile.addEventListener('change', () => { if (!mobile.matches) close(true, false); });
    window.addEventListener('pagehide', () => close(true, false));
    window.addEventListener('pageshow', () => { if (drawer.open) close(true); });
    // Let an existing reservation/account dialog own focus and the top layer if opened programmatically.
    new MutationObserver(() => {
        if (drawer.open && document.querySelector('dialog.ereserve-modal[open]')) close(true, false);
    }).observe(document.body, {subtree: true, attributes: true, attributeFilter: ['open']});
})();
