<x-layouts.user :title="($systemWide ? 'Super Admin Dashboard' : 'Admin Dashboard') . ' - eReserve'" active="dashboard">
    <link rel="stylesheet" href="{{ asset('css/dashboard-overview.css') }}?v={{ filemtime(public_path('css/dashboard-overview.css')) }}">
    <div class="dashboard-overview">
        <section class="welcome-section">
            <h2>Welcome, {{ auth()->user()->name }}</h2>
            <p>{{ $systemWide ? 'System-wide overview of eReserve operations' : 'Overview of facilities and reservation activity' }}</p>
        </section>
        @php
            $cards = $systemWide
                ? [['Total Barangays', $totalBarangays, 'blue'], ['Total Admins', $totalAdmins, 'yellow'], ['Total Facilities & Equipment', $totalFacilities, 'green'], ['Total Reservations', $totalReservations, 'red']]
                : [['Available Facilities', $facilityCount, 'blue'], ['Pending Requests', $pendingCount, 'yellow'], ['Accepted Reservations', $acceptedCount, 'green'], ['Rejected Requests', $rejectedCount, 'red']];
        @endphp
        <section class="stats-grid" aria-label="{{ $systemWide ? 'System summary' : 'Barangay reservation summary' }}">
            @foreach ($cards as [$label, $count, $color])
                <article class="stat-card stat-{{ $color }}">
                    <div><span>{{ $label }}</span><strong>{{ number_format($count) }}</strong></div>
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        @if ($loop->index === 0)
                            <path d="M8 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M8 9H5a2 2 0 0 0-2 2v10h19M12 7h4M12 11h4M12 15h4" />
                        @elseif ($systemWide && $loop->index === 1)
                            <circle cx="9" cy="7" r="4" /><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M19 8v6M22 11h-6" />
                        @elseif ($systemWide)
                            <rect x="3" y="4" width="18" height="18" rx="2" /><path d="M8 2v4M16 2v4M3 10h18" />
                        @else
                            <circle cx="12" cy="12" r="9" />
                            <path d="{{ $loop->index === 1 ? 'M12 7v6l4 2' : ($loop->index === 2 ? 'm8 12 3 3 6-7' : 'm15 9-6 6M9 9l6 6') }}" />
                        @endif
                    </svg>
                </article>
            @endforeach
        </section>
        <section class="dashboard-grid">
            <article class="content-card">
                @if ($systemWide)
                    <h3>Recent System Activity</h3>
                    <p class="dashboard-note">Recent account and resource creation, and latest reservation updates.</p>
                    <div class="recent-reservation-list">
                        @forelse ($recentActivity as $activity)
                            <a class="recent-reservation-item" href="{{ $activity['url'] }}">
                                <span><strong>{{ $activity['title'] }}</strong><small>{{ $activity['detail'] }}</small><small>{{ $activity['barangay'] }} &middot; {{ $activity['at']?->format('M j, Y g:i A') }}</small></span>
                            </a>
                        @empty
                            <p>No system activity yet.</p>
                        @endforelse
                    </div>
                @else
                    <h3>Today's Reservations</h3>
                    <p class="dashboard-note">{{ today()->format('M j, Y') }} &middot; Current and upcoming reservations first. Up to 6 shown.</p>
                    @include('dashboards.reservations', ['items' => $todaysReservations, 'todayList' => true])
                @endif
            </article>
            <article class="content-card">
                <h3>Recent Reservations</h3>
                <p class="dashboard-note">The 4 newest reservation requests.</p>
                @include('dashboards.reservations', ['items' => $recentReservations, 'todayList' => false])
            </article>
        </section>
    </div>
</x-layouts.user>
