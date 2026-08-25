<x-layouts.user title="My Reservations - eReserve" active="reservations">
    <section class="page-heading reservations-heading">
        <h2>My Reservations</h2>
        <p>View and manage your facility reservations</p>
    </section>

    <form method="GET" action="{{ route('reservations.index') }}" class="filter-card reservation-filter-card" aria-label="Reservation filters">
        <div class="filter-group">
            <label for="status">Filter by Status</label>
            <select id="status" name="status" data-auto-submit>
                <option value="all" @selected($selectedStatus === 'all')>All Status</option>
                <option value="pending" @selected($selectedStatus === 'pending')>Pending</option>
                <option value="approved" @selected($selectedStatus === 'approved')>Approved</option>
                <option value="declined" @selected($selectedStatus === 'declined')>Declined</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="sort">Sort by</label>
            <select id="sort" name="sort" data-auto-submit>
                <option value="date" @selected($selectedSort === 'date')>Date</option>
                <option value="facility" @selected($selectedSort === 'facility')>Facility</option>
                <option value="status" @selected($selectedSort === 'status')>Status</option>
            </select>
        </div>
    </form>

    @if ($reservations->isEmpty())
        <section class="reservation-empty-card" aria-label="No reservations">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M8 2v4M16 2v4M3 10h18" />
                <rect x="3" y="4" width="18" height="18" rx="2" />
            </svg>
            <p>No reservations found</p>
            <a class="browse-facilities-button" href="{{ route('facilities') }}">Browse Facilities</a>
        </section>
    @else
        <section class="reservation-list" aria-label="Reservation list">
            @foreach ($reservations as $reservation)
                @php
                    $statusClass = 'reservation-status-' . strtolower($reservation->status);
                    $reservationDate = \Illuminate\Support\Carbon::parse($reservation->reservation_date);
                    $startTime = \Illuminate\Support\Carbon::parse($reservation->start_time)->format('g:i A');
                    $endTime = \Illuminate\Support\Carbon::parse($reservation->end_time)->format('g:i A');
                @endphp

                <article class="reservation-list-card">
                    <div class="reservation-list-main">
                        <div class="reservation-list-title">
                            <h3>{{ $reservation->facility_name }}</h3>
                            <span class="reservation-status {{ $statusClass }}">{{ ucfirst($reservation->status) }}</span>
                        </div>

                        <div class="reservation-list-meta">
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M8 2v4M16 2v4M3 10h18" />
                                    <rect x="3" y="4" width="18" height="18" rx="2" />
                                </svg>
                                {{ $reservationDate->format('M j, Y') }}
                            </span>
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <path d="M12 7v6l4 2" />
                                </svg>
                                {{ $startTime }} - {{ $endTime }}
                            </span>
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg>
                                {{ $reservation->location }}
                            </span>
                        </div>

                        <p>{{ $reservation->purpose }}</p>
                    </div>

                    <a class="reservation-details-link" href="{{ route('facilities.show', $reservation->facility_slug) }}">View Facility</a>
                </article>
            @endforeach
        </section>
    @endif

    <script>
        document.querySelectorAll('[data-auto-submit]').forEach((select) => {
            select.addEventListener('change', () => select.form.submit());
        });
    </script>
</x-layouts.user>
