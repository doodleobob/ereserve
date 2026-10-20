(() => {
    const workflow = document.querySelector('[data-resident-booking]');
    const form = workflow?.querySelector('form[data-unified-booking]');
    if (!form) return;
    const calendar = workflow.querySelector('[data-booking-calendar]');
    const feedback = calendar.querySelector('[data-calendar-feedback]');
    const date = form.elements.namedItem('reservation_date');
    let controller, requestId = 0;
    const highlight = () => {
        calendar.querySelectorAll('.calendar-day[href]').forEach(day => {
            const selected = new URL(day.href).searchParams.get('date') === date.value;
            day.classList.toggle('selected', selected);
            if (selected) day.setAttribute('aria-current', 'date');
            else day.removeAttribute('aria-current');
        });
    };
    const loadCalendar = async (url, focusSelector) => {
        controller?.abort();
        controller = new AbortController();
        const id = ++requestId;
        calendar.setAttribute('aria-busy', 'true');
        feedback.hidden = false;
        feedback.textContent = 'Updating calendar and hourly availability…';
        // Render the existing, scoped calendar on the server. Never replace the resident's form.
        try {
            const response = await fetch(url, {headers: {Accept: 'text/html'}, cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw Error('Calendar request failed');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const updated = html.querySelector('#availability');
            if (!updated) throw Error('Calendar missing');
            if (id !== requestId) return;
            calendar.querySelector('#availability').replaceWith(updated);
            feedback.hidden = true;
            highlight();
            if (focusSelector) calendar.querySelector(focusSelector)?.focus({preventScroll: true});
        } catch (error) {
            if (id !== requestId || error.name === 'AbortError') return;
            // Old hourly rows must never be presented as information about the newly selected date.
            calendar.querySelector('.day-schedule-card').hidden = true;
            feedback.textContent = 'Calendar could not be updated. Choose a date again to retry. Your entered details are saved in this form.';
        } finally {
            if (id === requestId) calendar.removeAttribute('aria-busy');
        }
    };
    const dateUrl = () => {
        const url = new URL(window.location.href);
        url.search = new URLSearchParams({date: date.value, month: date.value.slice(0, 7)});
        url.hash = '';
        return url;
    };
    const syncDate = () => {
        highlight();
        if (/^\d{4}-\d{2}-\d{2}$/.test(date.value)) {
            loadCalendar(dateUrl(), calendar.contains(document.activeElement) ? '.calendar-day[aria-current="date"]' : null);
        }
    };
    calendar.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href);
        if (link.matches('.calendar-nav-button')) {
            event.preventDefault();
            url.searchParams.set('date', date.value);
            loadCalendar(url, `[aria-label="${link.getAttribute('aria-label')}"]`);
        } else if (link.matches('.calendar-day, .schedule-slot-available, .equipment-slot-time')) {
            event.preventDefault();
            const previousDate = date.value;
            date.value = url.searchParams.get('date') || date.value;
            for (const name of ['start_time', 'end_time', 'end_next_day']) {
                if (url.searchParams.has(name)) form.elements.namedItem(name).value = url.searchParams.get(name);
            }
            const changed = link.matches('.calendar-day') || previousDate !== date.value ? date : form.elements.namedItem('start_time');
            changed.dispatchEvent(new Event('change', {bubbles: true}));
        }
    });
    date.addEventListener('change', syncDate);
    form.addEventListener('input', () => workflow.querySelector('[data-selection-error]')?.remove());
    form.addEventListener('change', () => workflow.querySelector('[data-selection-error]')?.remove());
    // Validation redirects may restore a date different from the original bookmarked URL.
    const selectedDay = calendar.querySelector('.calendar-day[aria-current="date"]');
    if (!selectedDay || new URL(selectedDay.href).searchParams.get('date') !== date.value) syncDate();
})();
