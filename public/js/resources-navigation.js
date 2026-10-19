(() => {
    const menus = [...document.querySelectorAll('[data-resources-navigation]')];
    const sync = menu => menu.querySelector('summary').setAttribute('aria-expanded', String(menu.open));
    const close = menu => { menu.open = false; sync(menu); };
    const stateObserver = new MutationObserver(records => records.forEach(record => sync(record.target)));
    menus.forEach(menu => {
        // Native details owns click/Enter/Space toggling; mirror that state for assistive technology.
        sync(menu);
        stateObserver.observe(menu, {attributes: true, attributeFilter: ['open']});
        menu.addEventListener('keydown', event => {
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
    document.getElementById('mobile-navigation')?.addEventListener('close', () => menus.forEach(close));
    window.addEventListener('pagehide', () => menus.forEach(close));
    window.addEventListener('pageshow', () => menus.forEach(close));
    window.matchMedia('(max-width: 1279px)').addEventListener('change', () => menus.forEach(close));
})();
