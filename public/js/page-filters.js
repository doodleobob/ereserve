document.querySelectorAll('[data-auto-submit]').forEach((field) => {
    field.addEventListener('change', () => field.form.submit());
});

['calendar-barangay', 'resource-type'].forEach((id) => {
    const field = document.getElementById(id);
    field?.addEventListener('change', () => {
        field.form.elements.facility.value = '';
        field.form.submit();
    });
});

const calendarDate = document.getElementById('date');
calendarDate?.addEventListener('change', () => {
    calendarDate.form.elements.month.value = calendarDate.value.slice(0, 7);
    calendarDate.form.submit();
});
