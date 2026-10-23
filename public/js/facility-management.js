(() => {
    const previews = new WeakMap(), observed = new WeakSet();
    const clearPreview = input => {
        (previews.get(input) || []).forEach(url => URL.revokeObjectURL(url));
        previews.delete(input);
    };
    document.addEventListener('change', event => {
        const form = event.target.closest('.facility-modal-form');
        if (!form || !event.target.matches('[data-facility-photo-input], input[name="remove_photo_ids[]"]')) return;
        const input = form.querySelector('[data-facility-photo-input]'), preview = form.querySelector('[data-facility-photo-preview]');
        const dialog = form.closest('dialog');
        if (!observed.has(dialog)) {
            observed.add(dialog);
            dialog.addEventListener('modal:reset', () => dialog.querySelectorAll('[data-facility-photo-input]').forEach(clearPreview));
        }
        clearPreview(input);
        const retained = [...form.querySelectorAll('input[name="remove_photo_ids[]"]')].filter(control => !control.checked).length;
        const files = [...input.files];
        input.setCustomValidity(retained + files.length > 4 ? `You can add up to ${Math.max(0, 4 - retained)} more photos.` : '');
        window.EReserveValidation.set(form, 'photos', input.validationMessage ? {[input.name]: input.validationMessage} : {});
        preview.replaceChildren(); preview.hidden = true;
        if (!input.checkValidity()) { input.reportValidity(); return; }
        const urls = files.map((file, index) => {
            const url = URL.createObjectURL(file), image = document.createElement('img');
            image.src = url; image.alt = `Selected photo ${index + 1}`; preview.appendChild(image); return url;
        });
        previews.set(input, urls); preview.hidden = !files.length;
    });
})();

function filterFacilities(updateUrl = true) {
    const searchInput = document.querySelector('#facility-search');
    const categorySelect = document.querySelector('#facility-category');
    const statusSelect = document.querySelector('#facility-status');
    if (!searchInput || !categorySelect || !statusSelect) return;
    const cards = Array.from(document.querySelectorAll('[data-facility-card]'));
    const emptyMessage = document.querySelector('[data-empty-facilities]');
    const search = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const category = categorySelect ? categorySelect.value : 'all';
    const status = statusSelect ? statusSelect.value : 'all';
    let visibleCount = 0;

    cards.forEach((card) => {
        const matchesSearch = !search || card.dataset.name.includes(search);
        const matchesCategory = category === 'all' || card.dataset.category === category;
        const matchesStatus = status === 'all' || card.dataset.status === status;
        const isVisible = matchesSearch && matchesCategory && matchesStatus;

        card.hidden = !isVisible;
        if (isVisible) {
            visibleCount += 1;
        }
    });

    if (emptyMessage) {
        emptyMessage.hidden = visibleCount !== 0;
    }
    if (updateUrl && /^https?:$/.test(window.location.protocol)) {
        const url = new URL(window.location.href);
        for (const field of [searchInput, categorySelect, statusSelect]) {
            if (field.value && (field === searchInput || field.value !== 'all')) url.searchParams.set(field.name, field.value);
            else url.searchParams.delete(field.name);
        }
        window.history.replaceState(window.history.state, '', url);
    }
}

document.addEventListener('input', (event) => {
    if (event.target.matches('#facility-search')) filterFacilities();
});
document.addEventListener('change', (event) => {
    if (event.target.matches('[data-browse-barangay]')) event.target.form.submit();
    if (event.target.matches('#facility-category, #facility-status')) filterFacilities();
});

filterFacilities(false);
window.addEventListener('pageshow', () => filterFacilities(false));

// Inventory previews are calculated by the same server rules as saving; no stock arithmetic lives here.
(() => {
    const states = new WeakMap();
    const update = (form, reset = false) => {
        const state = states.get(form) || {};
        states.set(form, state);
        clearTimeout(state.timer);
        state.controller?.abort();
        const controller = new AbortController();
        state.controller = controller;
        const type = form.elements.adjustment_type, quantity = form.elements.quantity;
        form.querySelector('[data-adjustment-guidance]').textContent = type.selectedOptions[0].dataset.guidance;
        const preview = form.querySelector('[data-stock-preview]'), status = form.querySelector('[data-stock-preview-status]');
        preview.hidden = true;
        window.EReserveValidation.set(form, 'schedule', {});
        status.textContent = 'Enter a quantity to preview the adjustment. Stock is rechecked when you save.';
        if (reset || !quantity.value) return;
        if (!quantity.validity.valid) {
            window.EReserveValidation.set(form, 'schedule', {quantity: quantity.validationMessage});
            return;
        }
        status.textContent = 'Checking stock preview…';
        state.timer = setTimeout(async () => {
            const timeout = setTimeout(() => controller.abort(), 10000);
            const current = () => state.controller === controller && form.isConnected && !form.closest('dialog').hasAttribute('aria-busy');
            try {
                const params = new URLSearchParams({adjustment_type: type.value, quantity: quantity.value});
                const response = await fetch(form.dataset.inventoryPreview + '?' + params, {cache: 'no-store', headers: {Accept: 'application/json'}, signal: controller.signal});
                const data = await response.json();
                if (!current()) return;
                if (!response.ok) {
                    if (response.status === 422) {
                        window.EReserveValidation.set(form, 'schedule', data.errors || {});
                        status.textContent = 'Preview unavailable. Review the highlighted fields or form message.';
                        return;
                    }
                    throw Error('Preview unavailable');
                }
                const rows = [...preview.querySelectorAll('[data-stock-row]')];
                if (!rows.every(row => Number.isInteger(data.before?.[row.dataset.stockRow]) && Number.isInteger(data.after?.[row.dataset.stockRow]))) throw Error('Invalid preview');
                rows.forEach(row => {
                    row.querySelector('[data-before]').textContent = data.before[row.dataset.stockRow];
                    row.querySelector('[data-after]').textContent = data.after[row.dataset.stockRow];
                });
                preview.hidden = false;
                status.textContent = 'Preview only. Stock and scheduled allocations are rechecked when you save.';
            } catch {
                if (current()) status.textContent = 'Stock preview could not be loaded. Saving will recheck the adjustment.';
            } finally {
                clearTimeout(timeout);
            }
        }, 250);
    };
    document.addEventListener('input', event => {
        const form = event.target.closest('form[data-inventory-preview]');
        if (form && ['adjustment_type', 'quantity'].includes(event.target.name)) update(form);
    });
    document.addEventListener('change', event => {
        const form = event.target.closest('form[data-inventory-preview]');
        if (form && ['adjustment_type', 'quantity'].includes(event.target.name)) update(form);
    });
    document.addEventListener('reset', event => {
        if (event.target.matches('form[data-inventory-preview]')) setTimeout(() => update(event.target, true), 0);
    });
})();
