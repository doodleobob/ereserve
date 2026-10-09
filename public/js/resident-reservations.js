(() => {
    const restoredFilters = new WeakSet();
    // The initial page is initialized by page-filters.js; refreshed markup needs its own listeners.
    document.addEventListener('modals:updated', () => {
        document.querySelectorAll('.resident-reservation-filters [data-auto-submit]').forEach(field => {
            if (restoredFilters.has(field)) return;
            restoredFilters.add(field);
            field.addEventListener('change', () => field.form.submit());
        });
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches('form[data-pending-reservation-action]')) return;
        event.preventDefault();
        const reservationId = form.dataset.reservationId;
        window.EReserveModal.submit(form, {
            label: 'Reservation',
            refresh: async () => {
                await window.EReserveModal.refreshMain();
                // The previous trigger was replaced with main; restore focus to the updated card.
                const target = document.getElementById('resident-details-button-' + reservationId)
                    || document.getElementById('resident-reservations-heading');
                target?.focus();
            },
        });
    });
})();
