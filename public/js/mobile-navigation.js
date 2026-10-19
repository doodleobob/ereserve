(() => {
    const trigger = document.querySelector('.mobile-menu-button');
    const drawer = document.getElementById('mobile-navigation');
    const backdrop = document.querySelector('.mobile-drawer-backdrop');
    const header = document.querySelector('.user-topbar');
    const heading = drawer?.querySelector('.mobile-drawer-heading');
    if (!trigger || !drawer || !backdrop || !header || !heading || typeof drawer.show !== 'function') return;
    const triggerHome = document.createComment('Mobile menu button');

    const mobile = window.matchMedia('(max-width: 1279px)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let timer, locked = false, scrollY = 0, bodyStyles, restoreFocus = true, inertElements = [];
    const bodyProperties = ['position', 'top', 'left', 'right', 'width'];

    const cleanup = () => {
        clearTimeout(timer);
        drawer.classList.remove('is-closing');
        backdrop.classList.remove('is-closing');
        backdrop.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', 'Open navigation menu');
        if (triggerHome.isConnected) triggerHome.replaceWith(trigger);
        inertElements.forEach(([element, inert]) => { element.inert = inert; });
        inertElements = [];
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
            backdrop.classList.add('is-closing');
            timer = setTimeout(finish, 180);
        }
    };
    trigger.hidden = false;
    document.documentElement.classList.add('mobile-navigation-ready');
    trigger.addEventListener('click', event => {
        if (!mobile.matches || document.querySelector('dialog.ereserve-modal[open]')) return;
        const keyboard = event.detail === 0;
        if (drawer.open) { close(false, keyboard); return; }
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
        // Reuse the same hamburger inside the full-height drawer, then return it
        // to the main header on close. Covered header controls must not take focus.
        trigger.replaceWith(triggerHome);
        heading.prepend(trigger);
        inertElements = [...document.querySelectorAll('.user-topbar, .user-nav, .user-main, body > footer')].map(element => [element, element.inert]);
        inertElements.forEach(([element]) => { element.inert = true; });
        backdrop.hidden = false;
        drawer.show();
        drawer.querySelector('.mobile-drawer-scroll').scrollTop = 0;
        trigger.setAttribute('aria-expanded', 'true');
        trigger.setAttribute('aria-label', 'Close navigation menu');
        if (keyboard) {
            (drawer.querySelector('a[aria-current="page"]') || drawer.querySelector('a[href]')).focus({preventScroll: true});
        } else if (drawer.contains(document.activeElement)) {
            // show() also moves focus natively. A tap must not leave
            // Dashboard focused on every route, including Safari's focus heuristics.
            document.activeElement.blur();
        }
    });
    drawer.addEventListener('cancel', event => { event.preventDefault(); close(); });
    drawer.addEventListener('close', () => { if (!drawer.open && locked) cleanup(); });
    const outside = target => !drawer.contains(target) && !trigger.contains(target);
    let outsidePress = false;
    document.addEventListener('pointerdown', event => { outsidePress = outside(event.target); });
    document.addEventListener('click', event => {
        if (drawer.open && outside(event.target) && (outsidePress || event.detail === 0)) {
            close(false, event.detail === 0 && !header.contains(event.target));
        }
        outsidePress = false;
    });
    drawer.addEventListener('click', event => {
        if (event.target.closest('a[href]')) close(true, event.detail === 0);
    });
    drawer.addEventListener('submit', () => close(true));
    // Resources handles its own Escape first; the next Escape closes navigation.
    document.addEventListener('keydown', event => {
        if (!drawer.open || event.defaultPrevented) return;
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        if (event.key !== 'Tab') return;
        const controls = [...drawer.querySelectorAll('button:not(:disabled), a[href], summary')].filter(control => control.getClientRects().length);
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
