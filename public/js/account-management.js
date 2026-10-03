(() => {
    const modals = window.EReserveModal;
    const enableActions = () => document.querySelectorAll('[data-account-status] button').forEach(button => button.disabled = false);
    enableActions();
    document.addEventListener('modals:updated', enableActions);
    let request;
    const loadDetails = async (url, trigger) => {
        const dialog = document.getElementById('account-details');
        if (!dialog || modals.processing) return;
        request?.abort(); request = new AbortController(); const current = request;
        const body = dialog.querySelector('[data-account-detail-body]');
        body.textContent = 'Loading account details…'; modals.open(dialog, trigger);
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest'}, signal: current.signal});
            if (!response.ok || response.redirected) throw Error('Unable to load this account. Sign in again and retry.');
            const fragment = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-account-details]');
            if (!fragment) throw Error('Account details are unavailable.');
            if (current !== request || !dialog.open) return;
            fragment.querySelectorAll('script').forEach(script => script.remove()); body.replaceChildren(fragment); enableActions();
        } catch (error) {
            if (error.name === 'AbortError' || current !== request || !dialog.open) return;
            body.textContent = error.message;
            const retry = document.createElement('button');
            retry.type = 'button'; retry.className = 'facility-modal-secondary'; retry.textContent = 'Retry';
            retry.addEventListener('click', () => loadDetails(url, trigger)); body.appendChild(retry);
        }
    };
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-account-view], #account-details .account-pagination a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault(); loadDetails(link.href, link);
    });
    document.addEventListener('submit', event => {
        const source = event.target;
        if (!source.matches('[data-account-status]')) return;
        event.preventDefault(); if (modals.processing) return;
        const dialog = document.getElementById('account-confirmation'), form = dialog.querySelector('form');
        const active = source.elements.is_active.value === '1';
        form.action = source.action; form.elements.is_active.value = source.elements.is_active.value;
        dialog.querySelector('[data-account-confirm-description]').textContent = (active ? 'Activate ' : 'Deactivate ') + source.dataset.accountName + '? ' + (active ? 'This account will be able to log in again.' : 'This account will no longer be able to log in.');
        dialog.querySelector('[data-account-confirm]').textContent = active ? 'Activate' : 'Deactivate';
        modals.close(document.getElementById('account-details'));
        modals.open(dialog, source.querySelector('button'));
    });
    document.addEventListener('close', event => { if (event.target.id === 'account-details') request?.abort(); }, true);
})();
