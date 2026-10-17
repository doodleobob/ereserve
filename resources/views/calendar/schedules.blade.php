@php
    $scheduleResources = $browseDateEvents->isEmpty() ? collect() : ($selectedFacility ? collect([$selectedFacility]) : $browseDateEvents
        ->groupBy('resource_id')->map(fn ($events) => [
            'id' => $events->first()['resource_id'],
            'name' => $events->first()['resource_name'],
            'slug' => $events->first()['resource_slug'],
            'category' => $events->first()['resource_category'],
            'barangay' => $events->first()['resource_barangay'],
        ]));
@endphp

<aside class="content-card day-schedule-card browse-schedules" aria-labelledby="schedule-heading">
    <h3 id="schedule-heading">Schedules for {{ $selectedDate->format('F j, Y') }}</h3>
    <p class="schedule-facility-name" id="schedule-summary">{{ $selectedFacility['name'] ?? 'All Facilities & Equipment' }} &bull; Barangay {{ $selectedBarangay }}</p>

    @if ($browseDateEvents->isEmpty())
        <p class="schedule-empty">No events scheduled for this date.</p>
    @endif

    @if ($browseDateEvents->isNotEmpty())
    <div class="schedule-list" data-schedule-list tabindex="0" role="region" aria-label="Daily schedule" aria-describedby="schedule-summary">
        @foreach ($scheduleResources as $resource)
            <section class="schedule-resource-group" aria-label="{{ $resource['name'] }} schedules">
                <div class="schedule-resource-heading">
                    <h4>{{ $resource['name'] }}</h4>
                    <p>{{ $resource['category'] }} &bull; Barangay {{ $resource['barangay'] }}</p>
                </div>
                @foreach ($allResources ? $browseDateEvents->where('resource_id', $resource['id']) : $browseDateEvents as $event)
                    @php
                        $eventStart = \Illuminate\Support\Carbon::parse($event['original_start'])->setTimezone('Asia/Manila');
                        $eventEnd = \Illuminate\Support\Carbon::parse($event['end'], 'Asia/Manila');
                        $isOfficialUse = $event['event_type'] === 'official_use';
                        $eventStatusClass = $isOfficialUse ? 'official-use' : 'booked';
                    @endphp
                    <div class="schedule-slot schedule-slot-{{ $eventStatusClass }}" data-event-type="{{ $event['event_type'] }}">
                        @if ($isOfficialUse)
                            <strong>Official Use</strong>
                        @else
                            <strong>Reservation &bull; Booked</strong>
                        @endif
                        @if ($event['title'] === 'In Use')<small>In Use</small>@endif
                        @if ($isOfficialUse)
                            <small>{{ isset($event['status']) ? ucfirst($event['status']) : 'Resource unavailable' }}</small>
                            @if (isset($event['purpose']))<small>{{ $event['purpose'] }}</small>@endif
                        @endif
                        <dl class="schedule-event-period">
                            <div><dt>Starts</dt><dd><time datetime="{{ $eventStart->format('c') }}">{{ $eventStart->format('M j, Y') }} &bull; {{ $eventStart->format('g:i A') }}</time></dd></div>
                            <div><dt>Ends</dt><dd><time datetime="{{ $eventEnd->format('c') }}">{{ $eventEnd->format('M j, Y') }} &bull; {{ $eventEnd->format('g:i A') }}</time></dd></div>
                        </dl>
                        @if ($event['note'])<small class="schedule-date-note">{{ $event['note'] }}</small>@endif
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>
    @endif
</aside>
