document.querySelectorAll('[data-auto-submit]').forEach((field) => {
    field.addEventListener('change', () => field.form.submit());
});

['calendar-barangay', 'resource-type'].forEach((id) => {
    const field = document.getElementById(id);
    field?.addEventListener('change', () => {
        const {facility, barangay, type} = field.form.elements;
        const selected = facility.selectedOptions[0];
        if (facility.value && facility.value !== 'all' &&
            (selected?.dataset.barangay !== barangay.value ||
                (type.value && type.value !== 'all' && selected?.dataset.type !== type.value))) {
            facility.value = 'all';
        }
        field.form.submit();
    });
});
