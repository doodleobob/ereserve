@php
    $locationMatchesBarangay = \Illuminate\Support\Str::lower(trim(preg_replace('/^barangay\s+/i', '', trim($facility['location']))))
        === \Illuminate\Support\Str::lower(trim($facility['barangay']));
@endphp

<x-modal id="view-facility-{{ $facility['slug'] }}" title="Facility Details" size="large" class="facility-view-modal">
    <div class="facility-modal-body facility-view-body">
        @include('facilities.partials.facility-gallery', ['facility' => $facility])

        <div class="facility-view-title">
            <h2>{{ $facility['name'] }}</h2>
            <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($facility['display_status']) }}">{{ $facility['display_status'] }}</span>
        </div>
        @if ($facility['current_reservation'])
            <p class="facility-view-current-use">{{ $facility['current_reservation']['start_time'] }} - {{ $facility['current_reservation']['end_time'] }} {{ $facility['current_reservation']['end_date_label'] }}</p>
        @endif

        <dl class="facility-view-facts">
            <div>
                <dt>{{ $locationMatchesBarangay ? 'Barangay / Location' : 'Barangay' }}</dt>
                <dd>{{ $locationMatchesBarangay ? $facility['location'] : $facility['barangay'] }}</dd>
            </div>
            <div>
                <dt>Open to</dt>
                <dd>{{ $facility['reservation_access'] === 'all_registered_users' ? 'All Registered Users' : $facility['barangay'].' Residents Only' }}</dd>
            </div>
            <div>
                <dt>Hourly Rate</dt>
                <dd>{{ \App\Support\Money::format($facility['hourly_rate']) }} / hour</dd>
            </div>
            <div class="facility-view-description">
                <dt>Description</dt>
                <dd>{{ $facility['description'] }}</dd>
            </div>
            <div>
                <dt>Category</dt>
                <dd>{{ $facility['category'] }}</dd>
            </div>
            @unless ($locationMatchesBarangay)
                <div>
                    <dt>Location</dt>
                    <dd>{{ $facility['location'] }}</dd>
                </div>
            @endunless
            <div>
                <dt>Capacity</dt>
                <dd>{{ $facility['capacity'] }} {{ $facility['capacity'] == 1 ? 'person' : 'persons' }}</dd>
            </div>
        </dl>
    </div>
    <div class="facility-modal-actions">
        <button type="button" class="facility-modal-secondary" data-modal-close>Close</button>
        @if ($residentDetails ?? false)
            <button type="button" class="facility-modal-primary" @if ($facility['can_reserve'] && $facility['is_available']) data-modal-open="reserve-facility-{{ $facility['slug'] }}" data-modal-transition @else disabled @endif>Reserve</button>
        @endif
    </div>
</x-modal>
