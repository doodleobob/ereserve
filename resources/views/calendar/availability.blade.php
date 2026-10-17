    @php
        $selectedFacilitySlug = $selectedFacility['slug'] ?? 'all';
        $selectedDateValue = $selectedDate->toDateString();
        $baseQuery = ($embeddedCalendar ? ['slug' => $selectedFacilitySlug] : []) + ['barangay' => $selectedBarangay, 'type' => $selectedType, 'facility' => $selectedFacilitySlug, 'month' => $month->format('Y-m')];
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
        $monthQuery = $readOnlyCalendar ? $baseQuery + ['date' => $selectedDateValue] : $baseQuery;
    @endphp

    <section class="calendar-layout{{ $readOnlyCalendar ? ' all-schedules-layout' : '' }}" id="availability">
        <article class="content-card calendar-card">
            <div class="calendar-header">
                <a class="calendar-nav-button" href="{{ route($calendarRoute, array_replace($monthQuery, ['month' => $previousMonth->format('Y-m')])) }}" aria-label="Previous month">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                </a>

                <h3>{{ $month->format('F Y') }}</h3>

                <a class="calendar-nav-button" href="{{ route($calendarRoute, array_replace($monthQuery, ['month' => $nextMonth->format('Y-m')])) }}" aria-label="Next month">
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

            <div class="calendar-grid" aria-label="{{ $month->format('F Y') }} {{ $allResources ? 'events' : 'availability' }}">
                @for ($blank = 0; $blank < $month->copy()->startOfMonth()->dayOfWeek; $blank++)
                    <span class="calendar-day calendar-day-empty" aria-hidden="true"></span>
                @endfor

                @foreach ($calendarDays as $day)
                    @php
                        $dateValue = $day['date']->toDateString();
                        $status = $day['status'] ?? null;
                        $statusClass = $allResources ? 'overview' : ($selectedFacility['is_available'] ? $status : 'unavailable');
                        $isSelected = $dateValue === $selectedDateValue;
                        $eventCount = $calendarEventsByDate->get($dateValue, collect())->count();
                        $dayLabel = $allResources ? ($eventCount ? $eventCount . ($eventCount === 1 ? ' event' : ' events') : 'No events') : $statusLabels[$status];
                    @endphp

                    <a
                        class="calendar-day calendar-day-{{ $statusClass }} {{ $isSelected ? 'selected' : '' }}{{ $readOnlyCalendar && $eventCount ? ' calendar-day-has-events' : '' }}"
                        href="{{ route($calendarRoute, $baseQuery + ['date' => $dateValue]) }}{{ $embeddedCalendar ? '#availability' : '' }}"
                        aria-label="{{ $day['date']->format('F j, Y') }}: {{ $dayLabel }}"
                        @if ($readOnlyCalendar && $isSelected) aria-current="date" @endif
                    >
                        <strong>{{ $day['date']->day }}</strong>
                        <span>{{ $dayLabel }}</span>
                        @if ($readOnlyCalendar && ! $allResources)
                            <span>{{ $eventCount ? $eventCount . ($eventCount === 1 ? ' event' : ' events') : 'No events' }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <div class="calendar-legend" aria-label="{{ $allResources ? 'Calendar information' : 'Availability legend' }}">
                @if ($allResources)
                    <span>Reservations and Official Use events across resources.</span>
                @elseif (! $selectedFacility['is_available'])
                    <span class="legend-item legend-unavailable">Unavailable</span>
                @else
                    @foreach ($statusLabels as $status => $label)
                        <span class="legend-item legend-{{ $status }}">{{ $label }}</span>
                    @endforeach
                @endif
            </div>
        </article>

        @if ($readOnlyCalendar)
            @include('calendar.schedules')
        @else
        <aside class="content-card day-schedule-card">
            <h3>{{ $selectedDate->format('F j, Y') }}</h3>
            <p class="schedule-facility-name">{{ $selectedFacility['name'] ?? 'All Facilities & Equipment' }}</p>
            @if ($allResources)
                <p>Select a resource first to view time slots and make a reservation.</p>
                @if ($selectedDateEvents->isEmpty())<p>No bookings or Official Use events for this date.</p>@endif
            @endif

            <div class="schedule-list" aria-label="Daily schedule">
                @foreach ($selectedDateEvents as $event)
                    @php
                        $eventStart = \Illuminate\Support\Carbon::parse($event['start']);
                        $eventEnd = \Illuminate\Support\Carbon::parse($event['end']);
                        $isOfficialUse = ($event['event_type'] ?? null) === 'official_use';
                        $eventStatusClass = $isOfficialUse ? 'official-use' : ($event['title'] === 'In Use' ? 'in-use' : 'booked');
                        $eventQuery = $baseQuery + [
                            'date' => $selectedDateValue,
                            'start_time' => $eventStart->format('H:i'),
                            'end_time' => $eventEnd->format('H:i'),
                        ];
                        $isActiveEvent = $selectedSlot
                            && $selectedSlot['start_time'] === $eventStart->format('H:i')
                            && $selectedSlot['end_time'] === $eventEnd->format('H:i');
                    @endphp

                    @if($isOfficialUse)
                    <div class="schedule-slot schedule-slot-official-use" data-event-type="official_use">
                        <span>{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}
                            @if($allResources)<small class="schedule-date-note">{{ $event['resource_name'] }}</small>@endif
                            @if($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                            @if(isset($event['purpose']))<small>{{ $event['purpose'] }}</small>@else<small>Resource unavailable</small>@endif
                        </span>
                        <strong>Official Use @if(isset($event['status']))<small>{{ ucfirst($event['status']) }}</small>@endif</strong>
                    </div>
                    @elseif($allResources || (! $isAdmin && $eventStart->lte(now('Asia/Manila'))))
                    <div class="schedule-slot schedule-slot-{{ $eventStatusClass }}" data-event-type="reservation">
                        <span>{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}
                            @if ($allResources)<small class="schedule-date-note">{{ $event['resource_name'] }}</small>@endif
                            @if($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                        </span>
                        <strong>{{ $event['title'] }}</strong>
                    </div>
                    @else
                    <a class="schedule-slot schedule-slot-{{ $eventStatusClass }} {{ $isActiveEvent ? 'selected' : '' }}" href="{{ route($calendarRoute, $eventQuery) }}{{ $embeddedCalendar ? '#reservation' : '' }}">
                        <span>{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}
                            @if ($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                        </span>
                        <strong>{{ $event['title'] }}</strong>
                    </a>
                    @endif
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
                        <a class="schedule-slot schedule-slot-{{ $slotStatusClass }} {{ $isActiveSlot ? 'selected' : '' }}" href="{{ route($calendarRoute, $slotQuery) }}{{ $embeddedCalendar ? '#reservation' : '' }}">
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
            @if ($embeddedCalendar && $selectedFacility['can_reserve'] && $selectedFacility['is_available'] && ! $selectedDate->isBefore(today('Asia/Manila')))
                <form method="GET" action="{{ route('facilities.show', $selectedFacilitySlug) }}#reservation" class="reservation-form custom-time-selection" data-future-reservation>
                    <h3>Choose your time</h3>
                    <p class="reservation-time-help">Choose a slot above or enter exact times. An earlier end time ends the next day.</p>
                    <input type="hidden" name="date" value="{{ $selectedDateValue }}">
                    <div class="time-grid">
                        <div class="reservation-group">
                            <label for="choose-start">Start Time</label>
                            <input id="choose-start" type="time" name="start_time" value="{{ request('start_time') }}" required>
                        </div>
                        <div class="reservation-group">
                            <label for="choose-end">End Time</label>
                            <input id="choose-end" type="time" name="end_time" value="{{ request('end_time') }}" required>
                        </div>
                    </div>
                    <button class="submit-reservation-button" type="submit">Use Selected Time</button>
                </form>
            @endif
        </aside>
        @endif
    </section>

