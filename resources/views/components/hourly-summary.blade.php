@props(['nextDay' => false, 'label' => 'End day'])
<label>{{ $label }}
    <select name="end_next_day" class="hourly-time-select">
        <option value="0" @selected(! $nextDay)>Same day</option>
        <option value="1" @selected($nextDay)>Next day (overnight)</option>
    </select>
</label>
<p class="reservation-time-help">Choose consecutive whole hours, at least 1 hour. For an overnight booking, explicitly select Next day.</p>
<p data-hourly-summary aria-live="polite">Select a start and end time to view the duration.</p>
