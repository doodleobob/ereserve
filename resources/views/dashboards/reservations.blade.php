<div class="recent-reservation-list">
    @forelse ($items as $reservation)
        <a class="recent-reservation-item" href="{{ route('reservations.index', ['reservation' => $reservation->id]) }}">
            <span>
                <strong>{{ $reservation->facility_name }}</strong>
                <small>{{ $reservation->user?->name ?? 'Unknown user' }}</small>
                @if ($systemWide)<small>{{ $reservation->barangay }}</small>@endif
                @if ($todayList)
                    <small>Start: {{ $reservation->period()->start->format('M j, g:i A') }} &middot; End: {{ $reservation->period()->end->format('M j, g:i A') }}</small>
                @else
                    <small>{{ \Illuminate\Support\Carbon::parse($reservation->reservation_date)->format('M j, Y') }}</small>
                @endif
            </span>
            <span class="reservation-status reservation-status-{{ $reservation->status }}">{{ ucfirst($reservation->status) }}</span>
        </a>
    @empty
        <p>{{ $todayList ? 'No reservations scheduled for today.' : 'No reservations yet.' }}</p>
    @endforelse
</div>
