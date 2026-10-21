<x-layouts.user title="{{ $facility['name'] }} - eReserve" :active="$facility['category'] === 'Equipment' ? 'equipment' : 'facilities'">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/resident-booking.css') }}?v={{ hash_file('sha256', public_path('css/resident-booking.css')) }}">
    @endpush
    <a class="back-link" href="{{ route($facility['category'] === 'Equipment' ? 'equipment' : 'facilities', ['barangay' => $facility['barangay']]) }}">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="m12 19-7-7 7-7" />
            <path d="M19 12H5" />
        </svg>
        Back to {{ $facility['category'] === 'Equipment' ? 'Equipment' : 'Facilities' }}
    </a>

    @if (session('reservation_status'))
        <div class="reservation-alert" role="status">
            {{ session('reservation_status') }}
        </div>
    @endif

    <section class="facility-detail-grid {{ isset($embeddedCalendar) ? 'resident-facility-information' : '' }}">
        <article class="facility-detail-card">
            @include('shared.facilities.partials.facility-gallery', ['facility' => $facility])

            <div class="facility-detail-body">
                <h2>{{ $facility['name'] }}</h2>
                <p>Barangay: {{ $facility['barangay'] }}</p>
                <p>Open to: {{ $facility['reservation_access'] === 'all_registered_users' ? 'All Registered Users' : $facility['barangay'].' Residents Only' }}</p>
                @php
                    $scheduleContext = isset($embeddedCalendar)
                        ? ['barangay' => $facility['barangay'], 'month' => $month->format('Y-m'), 'date' => $selectedDate->toDateString()]
                            + ($selectedSlot ? ['start_time' => $selectedSlot['start_time'], 'end_time' => $selectedSlot['end_time']] : [])
                        : [];
                @endphp
                <a class="back-link" href="{{ route('calendar', ['facility' => $facility['slug']] + $scheduleContext) }}">View All Schedules</a>
                <p>Hourly Rate: {{ \App\Support\Money::format($facility['hourly_rate']) }} / hour</p>
                <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($facility['display_status']) }}">
                    {{ $facility['display_status'] }}
                </span>
                @if ($facility['current_reservation'])
                    <p class="facility-detail-description">
                        {{ $facility['current_reservation']['start_time'] }} - {{ $facility['current_reservation']['end_time'] }}
                        {{ $facility['current_reservation']['end_date_label'] }}
                    </p>
                @endif
                <p class="facility-detail-description">{{ $facility['description'] }}</p>

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
                        <dd>{{ $facility['category'] }}</dd>
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
                        <dd>{{ $facility['location'] }}</dd>
                    </div>

                    <div>
                        <dt>
                            <span class="info-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                    <circle cx="9" cy="7" r="4" />
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                </svg>
                            </span>
                            {{ $facility['category'] === 'Equipment' ? 'Total Quantity' : 'Maximum Capacity' }}
                        </dt>
                        <dd>@if($facility['category'] === 'Equipment'){{ $facility['total_quantity'] ?? 'Awaiting stock verification' }}{{ $facility['total_quantity'] !== null ? ' units' : '' }}@else{{ $facility['capacity'] }} {{ $facility['capacity'] == 1 ? 'person' : 'persons' }}@endif</dd>
                    </div>
                </dl>
            </div>
        </article>

        @if (! isset($embeddedCalendar))
        <article class="reservation-card">
            <h2>Reserve This {{ $facility['category'] }}</h2>

            @if (! $facility['can_reserve'])
                <div class="reservation-empty-panel"><p>Residents Only</p></div>
            @elseif ($facility['category'] === 'Equipment' && $facility['total_quantity'] === null)
                <div class="reservation-empty-panel"><p>Equipment stock is awaiting verification by the barangay administrator.</p></div>
            @elseif ($facility['is_available'])
                @include('shared.facilities.partials.facility-reservation-form', ['inModal' => false])
            @else
                <div class="reservation-empty-panel reservation-unavailable-panel">
                    <p>This facility is currently unavailable for reservations.</p>
                </div>
            @endif
        </article>
        @endif
    </section>
    @if (isset($embeddedCalendar))
        <section class="page-heading">
            <h2>Availability Calendar</h2>
            <p>Select a date, then choose a time for {{ $facility['name'] }}.</p>
        </section>
        <div class="resident-booking-workflow" data-resident-booking>
        <div data-booking-calendar>
            <p data-calendar-feedback role="status" hidden></p>
            @include('shared.calendar.partials.availability')
        </div>
        <section class="reservation-card facility-booking-card" id="reservation">
            <h2>Reserve {{ $facility['name'] }}</h2>
            @if ($selectionError)
                <p class="form-error" data-selection-error role="alert">{{ $selectionError }}</p>
            @endif
            @if (! $facility['can_reserve'])
                <p class="reservation-empty-panel">Residents Only</p>
            @elseif (! $facility['is_available'])
                <p class="reservation-empty-panel">This facility is currently unavailable for reservations.</p>
            @elseif ($facility['category'] === 'Equipment' && $facility['total_quantity'] === null)
                <p class="reservation-empty-panel">Equipment stock is awaiting verification by the barangay administrator.</p>
            @else
                @include('shared.facilities.partials.facility-reservation-form', ['unifiedBooking' => true])
            @endif
        </section>
        </div>
        @push('scripts')
            <script src="{{ asset('js/resident-booking.js') }}?v={{ filemtime(public_path('js/resident-booking.js')) }}" defer></script>
        @endpush
    @endif
    @include('partials.reservation-time-validation')
</x-layouts.user>
