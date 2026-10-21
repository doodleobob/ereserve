<div class="recent-reservation-list">
    @forelse ($items as $reservation)
        <a class="recent-reservation-item" href="{{ route('reservations.index', ['reservation' => $reservation->id]) }}">
            <span>
                <strong>{{ $reservation->facility_name }} <span class="dashboard-resource-type">{{ $reservation->category }}</span></strong>
                <small>{{ $reservation->user?->name ?? 'Unknown user' }}</small>
                @if ($systemWide)<small>{{ $reservation->barangay }}</small>@endif
                @if ($todayList)
                    <small>Start: {{ $reservation->period()->start->format('M j, g:i A') }} &middot; End: {{ $reservation->period()->end->format('M j, g:i A') }}</small>
                @else
                    <small>{{ $reservation->period()->start->format('M j, Y g:i A') }} &ndash; {{ $reservation->period()->end->format('M j, Y g:i A') }}</small>
                @endif
                @if ($reservation->category === 'Equipment')
                    <small>{{ $reservation->quantity !== null ? $reservation->quantity.' units' : 'Legacy whole-resource hold' }}</small>
                @elseif ($reservation->attendees !== null)
                    <small>{{ $reservation->attendees }} attendees</small>
                @endif
            </span>
            <span class="reservation-status reservation-status-{{ $reservation->status }}">{{ ucfirst($reservation->status) }}</span>
        </a>
    @empty
        <p>{{ $todayList ? 'No reservations scheduled for today.' : 'No reservations yet.' }}</p>
    @endforelse
</div>
