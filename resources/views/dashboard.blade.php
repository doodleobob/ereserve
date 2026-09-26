<x-layouts.user title="Reservation Calendar - eReserve" active="dashboard">
    @php
        $selectedFacilitySlug = $selectedFacility['slug'] ?? '';
        $selectedDateValue = $selectedDate->toDateString();
        $baseQuery = ['facility' => $selectedFacilitySlug, 'month' => $month->format('Y-m')];
        $statusLabels = [
            'available' => 'Available',
            'partial' => 'Partially Booked',
            'full' => 'Fully Booked',
            'unavailable' => 'Unavailable',
        ];
        $calendarEventsByDate = $calendarEvents->groupBy(
            fn (array $event) => substr($event['start'], 0, 10)
        );
        $selectedDateEvents = $calendarEventsByDate->get($selectedDateValue, collect());
    @endphp

    <section class="page-heading reservations-heading">
        <h2>Reservation Calendar</h2>
        <p>Select a facility, choose a date, and reserve an available time slot</p>
    </section>

    @if (session('reservation_status'))
        <div class="reservation-alert" role="status">
            {{ session('reservation_status') }}
        </div>
    @endif

    @if ($selectedFacility === null)
        <section class="reservation-empty-card" aria-label="No facilities">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                <path d="M10 6h4M10 10h4M10 14h4" />
            </svg>
            <p>No facilities are available yet.</p>
        </section>
    @else
    <form method="GET" action="{{ route('dashboard') }}" class="filter-card calendar-filter-card" aria-label="Calendar filters">
        <div class="filter-group">
            <label for="facility">Facility</label>
            <select id="facility" name="facility" data-auto-submit>
                @foreach ($facilities as $facility)
                    <option value="{{ $facility['slug'] }}" @selected($facility['slug'] === $selectedFacilitySlug)>
                        {{ $facility['name'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="filter-group">
            <label for="date">Selected Date</label>
            <input id="date" name="date" type="date" value="{{ $selectedDateValue }}" data-auto-submit>
        </div>

        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
    </form>

    <section class="calendar-layout">
        <article class="content-card calendar-card">
            <div class="calendar-header">
                <a class="calendar-nav-button" href="{{ route('dashboard', ['facility' => $selectedFacilitySlug, 'month' => $previousMonth->format('Y-m')]) }}" aria-label="Previous month">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                </a>

                <h3>{{ $month->format('F Y') }}</h3>

                <a class="calendar-nav-button" href="{{ route('dashboard', ['facility' => $selectedFacilitySlug, 'month' => $nextMonth->format('Y-m')]) }}" aria-label="Next month">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </a>
            </div>

            <div class="calendar-weekdays" aria-hidden="true">
                <span>Sun</span>
                <span>Mon</span>
                <span>Tue</span>
                <span>Wed</span>
                <span>Thu</span>
                <span>Fri</span>
                <span>Sat</span>
            </div>

            <div class="calendar-grid" aria-label="{{ $month->format('F Y') }} availability">
                @for ($blank = 0; $blank < $month->copy()->startOfMonth()->dayOfWeek; $blank++)
                    <span class="calendar-day calendar-day-empty" aria-hidden="true"></span>
                @endfor

                @foreach ($calendarDays as $day)
                    @php
                        $dateValue = $day['date']->toDateString();
                        $status = $day['status'];
                        $statusClass = $selectedFacility['is_available'] ? $status : 'facility-unavailable';
                        $isSelected = $dateValue === $selectedDateValue;
                        $dayLabel = $statusLabels[$status];
                    @endphp

                    <a
                        class="calendar-day calendar-day-{{ $statusClass }} {{ $isSelected ? 'selected' : '' }}"
                        href="{{ route('dashboard', $baseQuery + ['date' => $dateValue]) }}"
                        aria-label="{{ $day['date']->format('F j, Y') }}: {{ $dayLabel }}"
                    >
                        <strong>{{ $day['date']->day }}</strong>
                        <span>{{ $dayLabel }}</span>
                    </a>
                @endforeach
            </div>

            <div class="calendar-legend" aria-label="Availability legend">
                @if (! $selectedFacility['is_available'])
                    <span class="legend-item legend-facility-unavailable">Unavailable</span>
                @else
                    @foreach ($statusLabels as $status => $label)
                        <span class="legend-item legend-{{ $status }}">{{ $label }}</span>
                    @endforeach
                @endif
            </div>
        </article>

        <aside class="content-card day-schedule-card">
            <h3>{{ $selectedDate->format('F j, Y') }}</h3>
            <p class="schedule-facility-name">{{ $selectedFacility['name'] }}</p>

            <div class="schedule-list" aria-label="Daily schedule">
                @foreach ($selectedDateEvents as $event)
                    @php
                        $eventStart = \Illuminate\Support\Carbon::parse($event['start']);
                        $eventEnd = \Illuminate\Support\Carbon::parse($event['end']);
                        $eventStatusClass = $event['title'] === 'In Use' ? 'in-use' : 'booked';
                        $eventQuery = $baseQuery + [
                            'date' => $selectedDateValue,
                            'start_time' => $eventStart->format('H:i'),
                            'end_time' => $eventEnd->format('H:i'),
                        ];
                        $isActiveEvent = $selectedSlot
                            && $selectedSlot['start_time'] === $eventStart->format('H:i')
                            && $selectedSlot['end_time'] === $eventEnd->format('H:i');
                    @endphp

                    <a class="schedule-slot schedule-slot-{{ $eventStatusClass }} {{ $isActiveEvent ? 'selected' : '' }}" href="{{ route('dashboard', $eventQuery) }}">
                        <span>{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}
                            @if ($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                        </span>
                        <strong>{{ $event['title'] }}</strong>
                    </a>
                @endforeach

                @foreach ($schedule as $slot)
                    @continue(in_array($slot['status'], ['booked', 'in_use'], true))

                    @php
                        $slotQuery = $baseQuery + [
                            'date' => $selectedDateValue,
                            'start_time' => $slot['start_time'],
                            'end_time' => $slot['end_time'],
                        ];
                        $isActiveSlot = $selectedSlot
                            && $selectedSlot['start_time'] === $slot['start_time']
                            && $selectedSlot['end_time'] === $slot['end_time'];
                        $slotStatusClass = str_replace('_', '-', $slot['status']);
                        $slotStatusLabel = match ($slot['status']) {
                            'booked' => 'Booked',
                            'in_use' => 'In Use',
                            'facility_unavailable' => 'Unavailable',
                            default => ucfirst($slot['status']),
                        };
                    @endphp

                    @if ($slot['status'] === 'available')
                        <a class="schedule-slot schedule-slot-{{ $slotStatusClass }} {{ $isActiveSlot ? 'selected' : '' }}" href="{{ route('dashboard', $slotQuery) }}">
                            <span>{{ $slot['label'] }}</span>
                            <strong>{{ $slotStatusLabel }}</strong>
                        </a>
                    @else
                        <span class="schedule-slot schedule-slot-{{ $slotStatusClass }}" aria-disabled="true">
                            <span>{{ $slot['label'] }}</span>
                            <strong>{{ $slotStatusLabel }}</strong>
                        </span>
                    @endif
                @endforeach
            </div>
        </aside>
    </section>

    <section class="facility-detail-grid calendar-reservation-grid">
        <article class="facility-detail-card">
            @include('partials.facility-gallery', ['facility' => $selectedFacility])
            <div class="facility-detail-body">
                <h2>{{ $selectedFacility['name'] }}</h2>
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

            @if (! $selectedFacility['is_available'])
                <div class="reservation-empty-panel reservation-unavailable-panel">
                    <p>This facility is currently unavailable for reservations.</p>
                </div>
            @elseif ($selectedSlot)
                <form method="POST" action="{{ route('reservations.store', $selectedFacilitySlug) }}" class="reservation-form">
                    @csrf
                    <input type="hidden" name="reservation_date" value="{{ $selectedDateValue }}">
                    <input type="hidden" name="start_time" value="{{ $selectedSlot['start_time'] }}">
                    <input type="hidden" name="end_time" value="{{ $selectedSlot['end_time'] }}">

                    <div class="selected-slot-summary">
                        <span>{{ $selectedDate->format('M j, Y') }}</span>
                        <strong>{{ $selectedSlot['label'] }}</strong>
                    </div>

                    @error('reservation_date')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                    @error('start_time')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                    @error('reservation')
                        <p class="form-error">{{ $message }}</p>
                    @enderror

                    <div class="reservation-group">
                        <label for="purpose">Purpose / Event Name <span>*</span></label>
                        <textarea id="purpose" name="purpose" placeholder="Describe the purpose of your reservation" required>{{ old('purpose') }}</textarea>
                        @error('purpose')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="reservation-group">
                        <label for="attendees">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </svg>
                            Expected Number of Attendees <span>*</span>
                        </label>
                        <input id="attendees" name="attendees" type="number" min="1" max="{{ $selectedFacility['capacity'] }}" value="{{ old('attendees') }}" placeholder="Max capacity: {{ $selectedFacility['capacity'] }}" required>
                        @error('attendees')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="reservation-actions">
                        <button type="submit" class="submit-reservation-button">Submit Reservation</button>
                        <a class="cancel-reservation-button" href="{{ route('dashboard', $baseQuery + ['date' => $selectedDateValue]) }}">Clear</a>
                    </div>
                </form>
            @else
                <div class="reservation-empty-panel">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M8 2v4M16 2v4M3 10h18" />
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                    </svg>
                    <p>Select an available time slot from the schedule.</p>
                </div>
            @endif
        </article>
    </section>

    @endif

    <script>
        document.querySelectorAll('[data-auto-submit]').forEach((field) => {
            field.addEventListener('change', () => field.form.submit());
        });
    </script>
</x-layouts.user>
