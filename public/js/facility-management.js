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
