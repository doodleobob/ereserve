<form method="GET" action="{{ route($calendarRoute) }}" class="filter-card filter-toolbar calendar-filter-card" aria-label="Calendar filters">
    <div class="filter-group">
        <label for="calendar-barangay">Barangay</label>
        <select id="calendar-barangay" name="barangay">
            @foreach ($barangays as $barangay)
                <option value="{{ $barangay }}" @selected($selectedBarangay === $barangay)>{{ $barangay }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-group">
        <label for="resource-type">Resource Type</label>
        <select id="resource-type" name="type">
            <option value="all" @selected($selectedType === 'all')>All Types</option>
            <option value="facility" @selected($selectedType === 'facility')>Facility</option>
            <option value="equipment" @selected($selectedType === 'equipment')>Equipment</option>
        </select>
    </div>
    <div class="filter-group">
        <label for="facility">Resource (optional)</label>
        <select id="facility" name="facility" data-auto-submit>
            <option value="all" @selected($selectedFacility === null)>All Facilities &amp; Equipment</option>
            @foreach ($facilities as $facility)
                <option value="{{ $facility['slug'] }}" data-barangay="{{ $facility['barangay'] }}" data-type="{{ strtolower($facility['category']) }}" @selected(($selectedFacility['slug'] ?? null) === $facility['slug'])>{{ $facility['name'] }}</option>
            @endforeach
        </select>
    </div>
    <input name="date" type="hidden" value="{{ $selectedDate->toDateString() }}">
    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
</form>
