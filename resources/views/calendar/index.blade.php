<x-layouts.user title="Reservation Calendar - eReserve" active="{{ $isAdmin ? 'calendar' : 'dashboard' }}">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/resident-booking.css') }}?v={{ filemtime(public_path('css/resident-booking.css')) }}">
    @endpush
    <section class="page-heading reservations-heading">
        <h2>Reservation Calendar</h2>
        <p>Browse and compare schedules across barangays. Open a facility to make a reservation.</p>
    </section>

    @if (session('reservation_status'))
        <div class="reservation-alert" role="status">
            {{ session('reservation_status') }}
        </div>
    @endif

    @include('calendar.filters')

    @if ($facilities->isEmpty())
        <p class="reservation-alert">{{ match($selectedType) {
            'facility' => 'No facilities are currently available for this barangay.',
            'equipment' => 'No equipment is currently available for this barangay.',
            default => 'No facilities or equipment are currently available for this barangay.',
        } }}</p>
    @endif


    @include('calendar.availability')

    @if ($selectedFacility && ! $isAdmin)
        <section class="content-card schedule-resource-link">
            <h3>{{ $selectedFacility['name'] }}</h3>
            <p>Barangay: {{ $selectedFacility['barangay'] }}</p>
            @if (! $selectedFacility['can_reserve'])<p>Residents Only</p>@endif
            @if (! $selectedFacility['is_available'])<p>This facility is currently unavailable for reservations.</p>@endif
            <a class="button button-primary" href="{{ route('facilities.show', ['slug' => $selectedFacility['slug'], 'barangay' => $selectedBarangay, 'month' => $month->format('Y-m'), 'date' => $selectedDate->toDateString()] + ($selectedSlot ? ['start_time' => $selectedSlot['start_time'], 'end_time' => $selectedSlot['end_time']] : [])) }}">View Facility</a>
        </section>
    @endif
    @if ($selectedFacility && $isAdmin)
    <section class="facility-detail-grid calendar-reservation-grid">
        <article class="facility-detail-card">
            @include('facilities.partials.facility-gallery', ['facility' => $selectedFacility])
            <div class="facility-detail-body">
                <h2>{{ $selectedFacility['name'] }}</h2>
                <p>Barangay: {{ $selectedFacility['barangay'] }} &bull; {{ $selectedFacility['category'] }}</p>
                <p>Hourly Rate: {{ \App\Support\Money::format($selectedFacility['hourly_rate']) }} / hour</p>
                <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($selectedFacility['display_status']) }}">
                    {{ $selectedFacility['display_status'] }}
                </span>
                @if ($selectedFacility['current_reservation'])
                    <p class="facility-detail-description">
                        {{ $selectedFacility['current_reservation']['start_time'] }} - {{ $selectedFacility['current_reservation']['end_time'] }}
                        {{ $selectedFacility['current_reservation']['end_date_label'] }}
                    </p>
                @endif
                <p class="facility-detail-description">{{ $selectedFacility['description'] }}</p>

                <dl class="facility-info-list">
                    <div>
                        <dt>
                            <span class="info-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                                    <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                                    <path d="M10 6h4M10 10h4M10 14h4" />
                                </svg>
                            </span>
                            Category
                        </dt>
                        <dd>{{ $selectedFacility['category'] }}</dd>
                    </div>

                    <div>
                        <dt>
                            <span class="info-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg>
                            </span>
                            Location
                        </dt>
                        <dd>{{ $selectedFacility['location'] }}</dd>
                    </div>
                </dl>
            </div>
        </article>

        <article class="reservation-card">
            <h2>Reserve Selected Time</h2>

            <div class="reservation-empty-panel"><p>Use Reservation Management to process requests for resources you manage.</p></div>
        </article>
    </section>

    @endif


    @push('scripts')
        <script src="{{ asset('js/page-filters.js') }}?v={{ filemtime(public_path('js/page-filters.js')) }}" defer></script>
    @endpush
</x-layouts.user>
