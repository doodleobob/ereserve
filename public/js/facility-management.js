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
        preview.replaceChildren(); preview.hidden = true;
        if (!input.checkValidity()) { input.reportValidity(); return; }
        const urls = files.map((file, index) => {
            const url = URL.createObjectURL(file), image = document.createElement('img');
            image.src = url; image.alt = `Selected photo ${index + 1}`; preview.appendChild(image); return url;
        });
        previews.set(input, urls); preview.hidden = !files.length;
    });
})();
