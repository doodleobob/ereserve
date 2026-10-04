<form method="GET" action="{{ route($calendarRoute) }}" class="filter-card filter-toolbar calendar-filter-card" aria-label="Calendar filters">
    <div class="filter-group">
        <label for="calendar-barangay">Barangay</label>
        <select id="calendar-barangay" name="barangay" onchange="this.form.elements.facility.value = ''; this.form.submit()">
            @foreach ($barangays as $barangay)
                <option value="{{ $barangay }}" @selected($selectedBarangay === $barangay)>{{ $barangay }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-group">
        <label for="resource-type">Resource Type</label>
        <select id="resource-type" name="type" onchange="this.form.elements.facility.value = ''; this.form.submit()">
            <option value="" @selected(! $selectedType)>Select Resource Type</option>
            <option value="facility" @selected($selectedType === 'facility')>Facility</option>
            <option value="equipment" @selected($selectedType === 'equipment')>Equipment</option>
        </select>
    </div>
    <div class="filter-group">
        <label for="facility">Resource</label>
        <select id="facility" name="facility" data-auto-submit @disabled(! $selectedType || $facilities->isEmpty())>
            <option value="" @selected($selectedFacility === null)>
                @if (! $selectedType)
                    Select Resource Type First
                @elseif ($facilities->isEmpty())
                    {{ $selectedType === 'facility' ? 'No facilities available' : 'No equipment available' }}
                @else
                    {{ $selectedType === 'facility' ? 'Select Facility' : 'Select Equipment' }}
                @endif
            </option>
            @foreach ($facilities as $facility)
                <option value="{{ $facility['slug'] }}" @selected(($selectedFacility['slug'] ?? null) === $facility['slug'])>{{ $facility['name'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="filter-group">
        <label for="date">Selected Date</label>
        <input id="date" name="date" type="date" value="{{ $selectedDate->toDateString() }}" onchange="this.form.elements.month.value = this.value.slice(0, 7); this.form.submit()">
    </div>
    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
</form>
