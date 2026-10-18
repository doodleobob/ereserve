(() => {
    const synchronize = form => {
        const selector = form.querySelector('[name="category"], [name="facility_id"]');
        if (!selector) return;
        const category = selector.name === 'category' ? selector.value : selector.selectedOptions[0]?.dataset.category;
        form.querySelectorAll('[data-resource-field]').forEach(group => {
            const enabled = group.dataset.resourceField === category;
            group.hidden = !enabled;
            group.querySelectorAll('input').forEach(input => {
                input.disabled = !enabled;
                input.required = enabled && !input.hasAttribute('data-legacy-quantity') && !input.hasAttribute('data-stock-unverified');
                if (selector.name === 'facility_id') {
                    const option = selector.selectedOptions[0];
                    input.max = option?.dataset.totalQuantity || 0;
                    // A blank quantity is only supported on the original legacy whole-resource use.
                    input.required = enabled && input.dataset.legacyQuantity !== selector.value;
                }
            });
        });
    };
    document.querySelectorAll('form').forEach(synchronize);
    document.addEventListener('change', event => {
        if (event.target.matches('[name="category"], [name="facility_id"]')) synchronize(event.target.form);
    });
    document.addEventListener('reset', event => setTimeout(() => synchronize(event.target), 0));
    document.querySelectorAll('[data-equipment-availability]').forEach(form => {
        let revision = 0;
        const update = async () => {
            const current = ++revision;
            const output = form.querySelector('[data-available-quantity]');
            const values = new URLSearchParams();
            for (const name of ['reservation_date', 'start_time', 'end_time']) {
                const value = form.elements[name]?.value;
                if (!value) {
                    output.textContent = 'Select a date and time to view Available Quantity.';
                    return;
                }
                values.set(name, value);
            }
            output.textContent = 'Checking Available Quantity…';
            try {
                const response = await fetch(form.dataset.equipmentAvailability + '?' + values, {headers: {Accept: 'application/json'}, cache: 'no-store'});
                const data = await response.json();
                if (current !== revision) return;
                if (!response.ok) throw Error('Invalid schedule');
                output.textContent = data.total_quantity === null ? 'Equipment stock is awaiting verification.' : `Available Quantity for selected time: ${data.available_quantity} units`;
            } catch {
                if (current === revision) output.textContent = 'Available Quantity could not be checked. Review your date and time and try again.';
            }
        };
        form.addEventListener('change', event => {
            if (['reservation_date', 'start_time', 'end_time'].includes(event.target.name)) update();
        });
        update();
    });
})();
