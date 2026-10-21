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
        $selectedDateEvents = $browseDateEvents;
        $monthQuery = $readOnlyCalendar ? $baseQuery + ['date' => $selectedDateValue] : $baseQuery;
        $equipmentSchedule = ($selectedFacility['category'] ?? null) === 'Equipment';
    @endphp

    <section class="calendar-layout{{ $readOnlyCalendar ? ' all-schedules-layout' : ' booking-calendar' }}" id="availability">
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
                        @if ($isSelected) aria-current="date" @endif
                    >
                        <strong>{{ $day['date']->day }}</strong>
                        <span class="calendar-day-status-label">{{ $dayLabel }}</span>
                        @if (! $readOnlyCalendar)
                            <span class="calendar-day-status-dot legend-item legend-{{ $statusClass }}" aria-hidden="true"></span>
                        @endif
                        @if ($readOnlyCalendar && ! $allResources)
                            <span>{{ $eventCount ? $eventCount . ($eventCount === 1 ? ' event' : ' events') : 'No events' }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <div class="calendar-legend" aria-label="{{ $allResources ? 'Calendar information' : 'Availability legend' }}">
                @if ($allResources)
                    <span>Reservations and Official Use events across resources.</span>
                @elseif ($readOnlyCalendar && ! $selectedFacility['is_available'])
                    <span class="legend-item legend-unavailable">Unavailable</span>
                @else
                    @foreach ($statusLabels as $status => $label)
                        <span class="legend-item legend-{{ $status }}{{ ! $selectedFacility['is_available'] && $status !== 'unavailable' ? ' booking-legend-extra' : '' }}">{{ $label }}</span>
                    @endforeach
                @endif
            </div>
        </article>

        @if ($readOnlyCalendar)
            @include('shared.calendar.partials.schedules')
        @else
        <aside class="content-card day-schedule-card" aria-labelledby="daily-schedule-heading">
            <header class="daily-schedule-header">
                <h3 id="daily-schedule-heading">{{ $selectedDate->format('F j, Y') }}</h3>
                @if ($selectedDateStatus)
                    <p class="selected-date-availability"><span class="legend-item legend-{{ $selectedDateStatus }}">{{ $statusLabels[$selectedDateStatus] }}</span></p>
                @endif
                <p class="schedule-facility-name">{{ $selectedFacility['name'] ?? 'All Facilities & Equipment' }}@if($equipmentSchedule) &mdash; {{ $selectedFacility['total_quantity'] !== null ? $selectedFacility['total_quantity'].' total units' : 'Stock awaiting verification' }}@endif</p>
            </header>
            @if ($allResources)
                <p>Select a resource first to view time slots and make a reservation.</p>
                @if ($selectedDateEvents->isEmpty())<p>No bookings or Official Use events for this date.</p>@endif
            @endif

            <div class="schedule-list" tabindex="0" role="region" aria-label="Daily schedule">
                @if ($equipmentSchedule)
                    @include('shared.calendar.partials.equipment-schedule')
                @else
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
                            @if(isset($event['purpose']))<small>{{ $event['purpose'] }}</small>@elseif(!array_key_exists('quantity', $event))<small>Resource unavailable</small>@endif
                            @include('shared.calendar.partials.quantity', ['event' => $event])
                        </span>
                        <strong>Official Use @if(isset($event['status']))<small>{{ ucfirst($event['status']) }}</small>@endif</strong>
                    </div>
                    @elseif($allResources || ! $isAdmin)
                    <div class="schedule-slot schedule-slot-{{ $eventStatusClass }}" data-event-type="reservation">
                        <span>{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}
                            @if ($allResources)<small class="schedule-date-note">{{ $event['resource_name'] }}</small>@endif
                            @if($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                        </span>
                        <strong>{{ $event['title'] }}@include('shared.calendar.partials.quantity', ['event' => $event])</strong>
                    </div>
                    @else
                    <a class="schedule-slot schedule-slot-{{ $eventStatusClass }} {{ $isActiveEvent ? 'selected' : '' }}" href="{{ route($calendarRoute, $eventQuery) }}{{ $embeddedCalendar ? '#reservation' : '' }}">
                        <span>{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}
                            @if ($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                        </span>
                        <strong>{{ $event['title'] }}@include('shared.calendar.partials.quantity', ['event' => $event])</strong>
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
                            'end_next_day' => (int) $slot['end_next_day'],
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
                            <strong>{{ $slotStatusLabel }}@if($slot['available_quantity'] !== null)<small>{{ $slot['available_quantity'] }} units available</small>@endif</strong>
                        </a>
                    @else
                        <span class="schedule-slot schedule-slot-{{ $slotStatusClass }}" aria-disabled="true">
                            <span>{{ $slot['label'] }}</span>
                            <strong>{{ $slotStatusLabel }}</strong>
                        </span>
                    @endif
                @endforeach
                @endif
            </div>
        </aside>
        @endif
    </section>

