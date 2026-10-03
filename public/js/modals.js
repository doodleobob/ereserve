(() => {
    const triggers = new WeakMap(), initialized = new WeakSet(), cleaned = new WeakSet();
    let processing = false;
    const syncScroll = () => document.documentElement.classList.toggle('modal-open', !!document.querySelector('dialog.ereserve-modal[open]'));
    const toast = (message, error = false) => {
        document.querySelector('.reservation-table-toast')?.remove();
        const notice = document.createElement('div');
        notice.className = 'reservation-table-toast';
        notice.setAttribute('role', error ? 'alert' : 'status');
        notice.dataset.error = String(error);
        notice.textContent = message;
        document.body.appendChild(notice);
        setTimeout(() => notice.remove(), error ? 12000 : 6000);
    };
    const clearErrors = form => {
        form.querySelectorAll('[data-modal-field-error]').forEach(error => error.remove());
        form.querySelectorAll('[data-modal-invalid]').forEach(control => {
            control.removeAttribute('aria-invalid');
            if (control.dataset.modalDescribedby) control.setAttribute('aria-describedby', control.dataset.modalDescribedby);
            else control.removeAttribute('aria-describedby');
            delete control.dataset.modalDescribedby;
            delete control.dataset.modalInvalid;
        });
        const summary = form.querySelector('[data-action-error]');
        if (summary) summary.hidden = true;
    };
    const showErrors = (form, errors, message) => {
        const summary = form.querySelector('[data-action-error]');
        if (summary) { summary.textContent = message; summary.hidden = false; }
        let first;
        Object.entries(errors || {}).forEach(([name, messages], index) => {
            const controls = [...form.elements].filter(control => {
                const normalized = control.name?.replace(/\[([^\]]*)\]/g, '.$1').replace(/\.$/, '');
                return normalized && (normalized === name || name.startsWith(normalized + '.') || normalized.startsWith(name + '.'));
            });
            if (!controls.length) return;
            const error = document.createElement('p');
            error.className = 'form-error';
            error.dataset.modalFieldError = '';
            error.id = form.closest('dialog').id + '-error-' + index;
            error.textContent = [].concat(messages).join(' ');
            controls[controls.length - 1].after(error);
            controls.forEach(control => {
                control.dataset.modalInvalid = '';
                control.dataset.modalDescribedby = control.getAttribute('aria-describedby') || '';
                control.setAttribute('aria-invalid', 'true');
                control.setAttribute('aria-describedby', [control.dataset.modalDescribedby, error.id].filter(Boolean).join(' '));
            });
            first ||= controls.find(control => control.type !== 'hidden');
        });
        return first;
    };
    const open = (dialog, trigger = document.activeElement) => {
        if (!dialog || dialog.open || processing) return;
        initialize(dialog);
        cleaned.delete(dialog);
        triggers.set(dialog, trigger);
        dialog.showModal();
        dialog.scrollTop = 0;
        syncScroll();
    };
    const close = (dialog, force = false) => {
        if (dialog && (force || !dialog.hasAttribute('aria-busy'))) { dialog.close(); cleanup(dialog); }
    };
    const cleanup = dialog => {
        if (dialog.open || cleaned.has(dialog)) return;
        cleaned.add(dialog);
        dialog.querySelectorAll('form').forEach(form => { form.reset(); clearErrors(form); });
        dialog.querySelectorAll('[data-facility-photo-preview]').forEach(preview => { preview.replaceChildren(); preview.hidden = true; });
        dialog.querySelectorAll('input[type=file]').forEach(input => input.setCustomValidity(''));
        dialog.dispatchEvent(new Event('modal:reset'));
        syncScroll();
        const trigger = triggers.get(dialog);
        if (trigger?.isConnected && !document.querySelector('dialog.ereserve-modal[open]')) trigger.focus();
    };
    const initialize = dialog => {
        if (initialized.has(dialog)) return;
        initialized.add(dialog);
        dialog.addEventListener('cancel', event => { if (dialog.hasAttribute('aria-busy')) event.preventDefault(); });
        dialog.addEventListener('close', () => cleanup(dialog));
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close(dialog);
        });
    };
    const initializeAll = () => { document.querySelectorAll('dialog.ereserve-modal').forEach(initialize); syncScroll(); };
    const refreshMain = async () => {
        const response = await fetch(window.location.href, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'text/html'}});
        if (!response.ok || response.redirected) throw Error('Unable to refresh this page.');
        const next = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('main');
        if (!next || next.dataset.modalPage !== document.querySelector('main')?.dataset.modalPage) throw Error('This page is unavailable. Sign in again and retry.');
        next.querySelectorAll('script').forEach(script => script.remove());
        document.querySelector('main').replaceWith(next);
        initializeAll();
        syncScroll();
        document.dispatchEvent(new Event('modals:updated'));
    };
    const submit = async (form, {refresh = refreshMain, label = 'Changes'} = {}) => {
        if (processing || !form.reportValidity()) return;
        const dialog = form.closest('dialog');
        if (!dialog) return;
        processing = true;
        const body = new FormData(form);
        clearErrors(form);
        const controls = [...dialog.querySelectorAll('button, input, select, textarea')].map(control => [control, control.disabled]);
        const submitButton = form.querySelector('button[type=submit]');
        const buttonText = submitButton?.textContent;
        dialog.setAttribute('aria-busy', 'true');
        controls.forEach(([control]) => control.disabled = true);
        if (submitButton) submitButton.textContent = 'Saving…';
        let saved = false, firstInvalid;
        try {
            const response = await fetch(form.getAttribute('action'), {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body});
            const data = await response.json().catch(() => ({}));
            if (!response.ok || response.redirected || !data.success) {
                const message = Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to save. Check your connection or sign in again.';
                firstInvalid = showErrors(form, data.errors, message);
                throw Error(message);
            }
            saved = true;
            close(dialog, true);
            await refresh();
            toast(data.message || 'Saved successfully.');
        } catch (error) {
            const message = saved ? `${label} saved, but the table could not refresh. Refresh the page to see the result.` : error.message;
            if (!saved && !form.querySelector('[data-action-error]:not([hidden])')) showErrors(form, {}, message);
            toast(message, true);
        } finally {
            processing = false;
            dialog.removeAttribute('aria-busy');
            controls.forEach(([control, disabled]) => control.disabled = disabled);
            if (submitButton) submitButton.textContent = buttonText;
            firstInvalid?.focus();
        }
    };
    window.EReserveModal = {open, close, submit, toast, refreshMain, initialize: initializeAll, get processing() { return processing; }};
    initializeAll();
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-modal-open], [data-reservation-open]');
        if (trigger) {
            event.preventDefault();
            if (trigger.disabled || processing) return;
            const target = document.getElementById(trigger.dataset.modalOpen || trigger.dataset.reservationOpen);
            if (!target) return;
            let returnFocus = trigger;
            const source = trigger.hasAttribute('data-modal-transition') ? trigger.closest('dialog') : null;
            if (source) {
                if (source.hasAttribute('aria-busy')) return;
                returnFocus = triggers.get(source) || trigger;
                close(source);
            }
            open(target, returnFocus);
        }
        const dismiss = event.target.closest('[data-modal-close], [data-reservation-close]');
        if (dismiss) close(dismiss.closest('dialog'));
    });
    document.addEventListener('submit', event => {
        if (!event.target.matches('form[data-modal-action]')) return;
        event.preventDefault();
        submit(event.target);
    });
    document.addEventListener('reservations:updated', initializeAll);
    document.addEventListener('modals:updated', initializeAll);
})();
