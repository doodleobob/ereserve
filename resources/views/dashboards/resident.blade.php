<x-layouts.user title="Dashboard - eReserve" active="dashboard">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/resident-booking.css') }}?v={{ filemtime(public_path('css/resident-booking.css')) }}">
    @endpush
    <section class="page-heading">
        <h2>Welcome, {{ auth()->user()->name }}</h2>
        <p>Browse facilities and equipment, or keep track of your reservations.</p>
    </section>

    <div class="resident-dashboard-actions">
        <a class="button button-primary" href="{{ route('facilities') }}">Browse Facilities</a>
        <a class="button button-secondary" href="{{ route('calendar') }}">View All Schedules</a>
    </div>

    <section class="resident-reservation-counts" aria-label="My reservation summary">
        <a class="stat-card stat-yellow resident-count resident-count-pending" href="{{ route('reservations.index', ['status' => 'pending']) }}">
            <div><span>My Pending Reservations</span><strong>{{ number_format($pendingCount) }}</strong></div>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9" /><path d="M12 7v6l4 2" />
            </svg>
        </a>
        <a class="stat-card stat-green resident-count resident-count-accepted" href="{{ route('reservations.index', ['status' => 'accepted']) }}">
            <div><span>My Accepted Reservations</span><strong>{{ number_format($acceptedCount) }}</strong></div>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 6-7" />
            </svg>
        </a>
    </section>

    <section class="content-card resident-upcoming">
        <h2>Your Upcoming Schedule</h2>
        @if ($upcomingReservation)
            @php($period = $upcomingReservation->period())
            <article class="resident-upcoming-item">
                <div>
                    <h3>{{ $upcomingReservation->facility_name }}</h3>
                    <p>@if (filled($upcomingReservation->barangay)){{ $upcomingReservation->barangay }} · @endif{{ $period->start->format('M j, Y') }}</p>
                    <p>{{ $period->start->format('g:i A') }} – {{ $period->end->format('g:i A') }} {{ $period->endDateLabel() }}</p>
                </div>
                <x-status-badge :status="$upcomingReservation->status" />
            </article>
        @else
            <p class="resident-upcoming-empty">No upcoming scheduled reservations.</p>
        @endif
    </section>
</x-layouts.user>
