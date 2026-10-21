(() => {
    const menus = [...document.querySelectorAll('[data-resources-navigation]')];
    const desktop = window.matchMedia('(min-width: 1280px)');
    const position = menu => {
        const panel = menu.querySelector('.resources-navigation-links');
        panel.style.left = '';
        panel.style.maxHeight = '';
        if (!menu.open || !desktop.matches || !menu.closest('.user-nav')) return;
        const bounds = panel.getBoundingClientRect();
        // Keep the panel below its trigger, shifting only when it reaches a screen edge.
        panel.style.left = `${Math.max(12 - bounds.left, Math.min(0, window.innerWidth - 12 - bounds.right))}px`;
        panel.style.maxHeight = `${Math.max(0, window.innerHeight - bounds.top - 12)}px`;
    };
    const sync = menu => menu.querySelector('summary').setAttribute('aria-expanded', String(menu.open));
    const close = menu => { menu.open = false; sync(menu); };
    const stateObserver = new MutationObserver(records => records.forEach(record => { sync(record.target); position(record.target); }));
    menus.forEach(menu => {
        // Native details owns click/Enter/Space toggling; mirror that state for assistive technology.
        sync(menu);
        stateObserver.observe(menu, {attributes: true, attributeFilter: ['open']});
        menu.addEventListener('keydown', event => {
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                const links = [...menu.querySelectorAll('a[href]')];
                const index = links.indexOf(document.activeElement);
                menu.open = true;
                position(menu);
                const next = index < 0 ? (event.key === 'ArrowDown' ? 0 : links.length - 1)
                    : (index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
                links[next]?.focus();
            }
            if (event.key === 'Escape' && menu.open) {
                event.preventDefault();
                event.stopPropagation();
                close(menu);
                menu.querySelector('summary').focus();
            }
        });
        menu.addEventListener('click', event => {
            if (event.target.closest('a[href]')) close(menu);
        });
        menu.addEventListener('focusout', event => {
            if (!menu.contains(event.relatedTarget)) close(menu);
        });
    });
    document.addEventListener('click', event => {
        menus.forEach(menu => { if (!menu.contains(event.target)) close(menu); });
    });
    window.addEventListener('resize', () => menus.forEach(position));
    window.addEventListener('scroll', () => menus.filter(menu => menu.open).forEach(position), {passive: true});
    document.getElementById('mobile-navigation')?.addEventListener('close', () => menus.forEach(close));
    window.addEventListener('pagehide', () => menus.forEach(close));
    window.addEventListener('pageshow', () => menus.forEach(close));
    window.matchMedia('(max-width: 1279px)').addEventListener('change', () => menus.forEach(close));
})();
