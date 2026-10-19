<div class="equipment-time-slots">
    <div class="schedule-section-heading">
        <h4>Time slots</h4>
        <p>Peak allocation within each slot, including Resident Bookings and Official Use.</p>
    </div>
    @foreach ($schedule as $slot)
        @php
            $unavailable = in_array($slot['status'], ['facility_unavailable', 'past'], true);
            $availability = $unavailable ? 'unavailable' : ($slot['available_quantity'] === 0 ? 'full' : ($slot['allocated_quantity'] > 0 ? 'partial' : 'available'));
            $slotQuery = $baseQuery + ['date' => $selectedDateValue, 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time']];
            $isActiveSlot = $selectedSlot && $selectedSlot['start_time'] === $slot['start_time'] && $selectedSlot['end_time'] === $slot['end_time'];
        @endphp
        <article class="equipment-slot equipment-slot-{{ $availability }}{{ $isActiveSlot ? ' selected' : '' }}" data-equipment-slot="{{ $slot['start_time'] }}">
            <div class="equipment-slot-header">
                @if ($slot['status'] === 'available')
                    <a class="equipment-slot-time" href="{{ route($calendarRoute, $slotQuery) }}#reservation" aria-label="Select {{ $slot['label'] }}, {{ $slot['available_quantity'] }} units remaining">{{ $slot['label'] }}</a>
                @else
                    <span class="equipment-slot-time">{{ $slot['label'] }}</span>
                @endif
                <strong class="equipment-slot-status">{{ $statusLabels[$availability] }}</strong>
            </div>
            @if (! $unavailable && $selectedFacility['total_quantity'] !== null)
                <p class="equipment-slot-allocation">{{ $slot['allocated_quantity'] }} of {{ $selectedFacility['total_quantity'] }} units allocated</p>
                <meter class="equipment-usage" min="0" max="{{ $selectedFacility['total_quantity'] }}" value="{{ $slot['allocated_quantity'] }}" aria-label="Peak allocated units for {{ $slot['label'] }}">{{ $slot['allocated_quantity'] }} of {{ $selectedFacility['total_quantity'] }}</meter>
                <p class="equipment-slot-remaining">{{ $slot['available_quantity'] }} units remaining</p>
            @else
                <p class="equipment-slot-allocation">{{ $slot['status'] === 'past' ? 'This time has passed.' : ($selectedFacility['total_quantity'] === null ? 'Stock awaiting verification.' : 'Reservations are currently unavailable.') }}</p>
            @endif
        </article>
    @endforeach
</div>

@if ($selectedDateEvents->isNotEmpty())
    <section class="equipment-booking-details" aria-label="Booking details">
        <div class="schedule-section-heading"><h4>Booking details</h4></div>
        @foreach ($selectedDateEvents as $event)
            @php
                $eventStart = \Illuminate\Support\Carbon::parse($event['start']);
                $eventEnd = \Illuminate\Support\Carbon::parse($event['end']);
                $isOfficialUse = $event['event_type'] === 'official_use';
                $eventQuery = $baseQuery + ['date' => $selectedDateValue, 'start_time' => $eventStart->format('H:i'), 'end_time' => $eventEnd->format('H:i')];
                $isActiveEvent = $selectedSlot && $selectedSlot['start_time'] === $eventStart->format('H:i') && $selectedSlot['end_time'] === $eventEnd->format('H:i');
            @endphp
            <article class="equipment-event schedule-slot-{{ $isOfficialUse ? 'official-use' : ($event['title'] === 'In Use' ? 'in-use' : 'booked') }}{{ $isActiveEvent ? ' selected' : '' }}" data-event-type="{{ $event['event_type'] }}">
                <div class="equipment-slot-header">
                    @if (! $isOfficialUse && ($isAdmin || $eventStart->gt(now('Asia/Manila'))))
                        <a class="equipment-slot-time" href="{{ route($calendarRoute, $eventQuery) }}#reservation">{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}</a>
                    @else
                        <span class="equipment-slot-time">{{ $eventStart->format('g:i A') }} - {{ $eventEnd->format('g:i A') }}</span>
                    @endif
                    <strong class="equipment-slot-status">{{ $isOfficialUse ? 'Official Use' : 'Resident Booking' }}</strong>
                </div>
                <p class="equipment-slot-allocation">{{ $event['whole_resource'] ? 'Whole-resource hold (legacy booking)' : $event['quantity'].' units allocated' }}</p>
                @if ($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                @if ($event['title'] === 'In Use')<small>In Use</small>@endif
                @if (isset($event['purpose']))<small>{{ $event['purpose'] }}</small>@endif
                @if (isset($event['status']))<small>{{ ucfirst($event['status']) }}</small>@endif
            </article>
        @endforeach
    </section>
@endif
