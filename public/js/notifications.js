(() => {
    const center = document.querySelector('[data-notifications]');
    if (!center) return;
    const bell = center.querySelector('.notification-bell');
    const panel = center.querySelector('.notification-panel');
    const badge = center.querySelector('.notification-badge');
    const list = center.querySelector('[data-notification-list]');
    const more = center.querySelector('[data-notification-more]');
    const error = center.querySelector('[data-notification-error]');
    const token = document.querySelector('meta[name="csrf-token"]').content;
    let page = 1;
    let busy = false;
    const seenKey = `ereserve-notifications-${center.dataset.user}`;
    let seen;
    try { seen = new Set(JSON.parse(sessionStorage.getItem(seenKey) || '[]')); }
    catch { seen = new Set(); }
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const openForm = (item) => {
        const form = element('form');
        form.method = 'POST';
        form.action = item.open_url;
        const csrf = element('input');
        csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = token;
        const button = element('button', 'View Reservation', 'reservation-details-link');
        button.type = 'submit';
        form.append(csrf, button);
        return form;
    };
    const toast = (item) => {
        const card = element('article', '', 'notification-toast');
        const close = element('button', '×', 'notification-toast-close');
        close.type = 'button'; close.setAttribute('aria-label', 'Dismiss notification');
        close.addEventListener('click', () => card.remove());
        card.append(close, element('strong', item.data.title), element('p', item.data.message));
        item.data.details.forEach(text => card.append(element('p', text)));
        card.append(openForm(item));
        center.querySelector('.notification-toasts').append(card);
        setTimeout(() => card.remove(), 12000);
    };
    async function refresh(append = false) {
        if (busy) return;
        busy = true;
        try {
            const response = await fetch(`${center.dataset.url}?page=${append ? page + 1 : 1}`, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error('Unable to load');
            const result = await response.json();
            error.hidden = true;
            badge.hidden = result.unread_count === 0;
            badge.textContent = result.unread_count > 99 ? '99+' : result.unread_count;
            bell.setAttribute('aria-label', `Notifications, ${result.unread_count} unread`);
            if (!append) { list.replaceChildren(); page = 1; } else { page += 1; }
            if (!result.notifications.data.length && !append) list.append(element('p', 'No notifications yet.'));
            result.notifications.data.forEach(item => {
                const card = element('article', '', `notification-item${item.read ? '' : ' unread'}`);
                card.append(element('strong', item.data.title), element('p', item.data.message));
                item.data.details.forEach(text => card.append(element('p', text)));
                card.append(element('small', item.time), openForm(item));
                list.append(card);
                if (!append && !item.read && item.data.event === 'submitted' && center.dataset.admin === 'true' && !seen.has(item.id)) {
                    // Show at most three cards together; the dropdown retains every notification.
                    if (center.querySelector('.notification-toasts').children.length < 3) toast(item);
                    seen.add(item.id);
                }
            });
            try { sessionStorage.setItem(seenKey, JSON.stringify([...seen].slice(-500))); } catch {}
            more.hidden = !result.notifications.next_page_url;
        } catch { error.hidden = false; }
        finally { busy = false; }
    }
    const closePanel = () => { panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); };
    bell.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        bell.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) refresh();
    });
    document.addEventListener('click', event => { if (!center.contains(event.target)) closePanel(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) { closePanel(); bell.focus(); } });
    more.addEventListener('click', () => refresh(true));
    center.querySelector('[data-read-all]').addEventListener('click', async () => {
        try {
            const response = await fetch(center.dataset.readAll, {method: 'POST', headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}});
            if (!response.ok) throw new Error('Unable to update');
            await refresh();
        } catch { error.hidden = false; }
    });
    refresh();
    setInterval(() => { if (!document.hidden && (panel.hidden || page === 1)) refresh(); }, 15000);
})();
