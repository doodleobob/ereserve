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
        <a class="content-card resident-count" href="{{ route('reservations.index', ['status' => 'pending']) }}">
            <span>My Pending Reservations</span><strong>{{ $pendingCount }}</strong>
        </a>
        <a class="content-card resident-count" href="{{ route('reservations.index', ['status' => 'accepted']) }}">
            <span>My Accepted Reservations</span><strong>{{ $acceptedCount }}</strong>
        </a>
    </section>

    <section class="content-card resident-upcoming">
        <h2>Upcoming Reservations</h2>
        <p>Your next reservations, including any currently in progress.</p>
        @forelse ($upcomingReservations as $reservation)
            <article class="resident-upcoming-item">
                <div>
                    <h3>{{ $reservation->facility_name }}</h3>
                    <p>{{ $reservation->barangay }} · {{ $reservation->period()->start->format('M j, Y') }}</p>
                    <p>{{ $reservation->period()->start->format('g:i A') }} – {{ $reservation->period()->end->format('g:i A') }} {{ $reservation->period()->endDateLabel() }}</p>
                </div>
                <div>
                    <x-status-badge :status="$reservation->status" />
                    <a href="{{ route('reservations.index', ['reservation' => $reservation->id]) }}">View reservation</a>
                </div>
            </article>
        @empty
            <p>You have no upcoming reservations. Browse facilities to get started.</p>
        @endforelse
        <a class="back-link" href="{{ route('reservations.index') }}">View My Reservations</a>
    </section>
</x-layouts.user>
