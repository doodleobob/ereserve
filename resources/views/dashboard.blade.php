<x-layouts.user title="Dashboard - eReserve" active="dashboard">
    @php
        $user = auth()->user();
    @endphp

    <section class="welcome-section">
        <h2>Welcome, {{ $user->name }}</h2>
        <p>Overview of available facilities and your reservations</p>
    </section>

    <section class="stats-grid" aria-label="Reservation summary">
        <article class="stat-card stat-blue">
            <div>
                <span>Available Facilities</span>
                <strong>6</strong>
            </div>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M8 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16" />
                <path d="M8 9H5a2 2 0 0 0-2 2v10h19" />
                <path d="M12 7h4M12 11h4M12 15h4M6 14h2M6 18h2" />
            </svg>
        </article>

        <article class="stat-card stat-yellow">
            <div>
                <span>Pending Requests</span>
                <strong>0</strong>
            </div>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v6l4 2" />
            </svg>
        </article>

        <article class="stat-card stat-green">
            <div>
                <span>Approved Reservations</span>
                <strong>0</strong>
            </div>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="m8 12 3 3 6-7" />
            </svg>
        </article>

        <article class="stat-card stat-red">
            <div>
                <span>Declined Requests</span>
                <strong>0</strong>
            </div>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="m15 9-6 6M9 9l6 6" />
            </svg>
        </article>
    </section>

    <section class="dashboard-grid">
        <article class="content-card">
            <h3>Quick Actions</h3>
            <a class="quick-action quick-blue" href="{{ route('facilities') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M8 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16" />
                    <path d="M8 9H5a2 2 0 0 0-2 2v10h19" />
                    <path d="M12 7h4M12 11h4M6 14h2M6 18h2" />
                </svg>
                <span>
                    <strong>Browse Facilities</strong>
                    <small>View available facilities and equipment</small>
                </span>
            </a>
            <a class="quick-action quick-green" href="{{ route('reservations.index') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M8 2v4M16 2v4M3 10h18" />
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                </svg>
                <span>
                    <strong>My Reservations</strong>
                    <small>View and manage your reservations</small>
                </span>
            </a>
        </article>

        <article class="content-card recent-card">
            <h3>Recent Reservations</h3>
            <p>No reservations yet</p>
        </article>
    </section>
</x-layouts.user>
