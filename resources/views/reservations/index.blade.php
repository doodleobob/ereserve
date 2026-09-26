<x-layouts.user title="{{ $isAdmin ? 'Reservation Management' : 'My Reservations' }} - eReserve" active="reservations">
    <section class="page-heading reservations-heading">
        <h2>{{ $isAdmin ? 'Reservation Management' : 'My Reservations' }}</h2>
        <p>{{ $isAdmin ? 'Review and manage all reservation requests' : 'View and manage your facility reservations' }}</p>
    </section>

    @if (session('reservation_status'))
        <div class="reservation-alert" role="status">
            {{ session('reservation_status') }}
        </div>
    @endif

    @error('reservation')
        <div class="reservation-alert reservation-alert-error" role="alert">
            {{ $message }}
        </div>
    @enderror

    @error('total_payment')
        <div class="reservation-alert reservation-alert-error" role="alert">{{ $message }}</div>
    @enderror
    @error('payment_confirmed')
        <div class="reservation-alert reservation-alert-error" role="alert">{{ $message }}</div>
    @enderror

    @if ($isAdmin)
        <form method="GET" action="{{ route('reservations.index') }}" class="filter-card admin-reservation-filter-card" aria-label="Reservation filters">
            <div class="filter-group admin-search-group">
                <label for="search">Search</label>
                <div class="search-field">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.35-4.35" />
                    </svg>
                    <input id="search" name="search" type="search" value="{{ $selectedSearch }}" placeholder="Search by facility, user, or purpose...">
                </div>
            </div>

            <div class="filter-group">
                <label for="status">Status</label>
                <select id="status" name="status" data-auto-submit>
                    <option value="all" @selected($selectedStatus === 'all')>All Status</option>
                    <option value="pending" @selected($selectedStatus === 'pending')>Pending</option>
                    <option value="accepted" @selected($selectedStatus === 'accepted')>Accepted</option>
                    <option value="rejected" @selected($selectedStatus === 'rejected')>Rejected</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="date_range">Date Range</label>
                <select id="date_range" name="date_range" data-auto-submit>
                    <option value="all" @selected($selectedDateRange === 'all')>All Dates</option>
                    <option value="today" @selected($selectedDateRange === 'today')>Today</option>
                    <option value="week" @selected($selectedDateRange === 'week')>This Week</option>
                    <option value="month" @selected($selectedDateRange === 'month')>This Month</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="from_date">From</label>
                <input id="from_date" name="from_date" type="date" value="{{ $selectedFromDate }}" data-auto-submit>
            </div>

            <div class="filter-group admin-to-date-group">
                <label for="to_date">To</label>
                <input id="to_date" name="to_date" type="date" value="{{ $selectedToDate }}" data-auto-submit>
            </div>
        </form>
    @else
        <form method="GET" action="{{ route('reservations.index') }}" class="filter-card reservation-filter-card" aria-label="Reservation filters">
            <div class="filter-group">
                <label for="status">Filter by Status</label>
                <select id="status" name="status" data-auto-submit>
                    <option value="all" @selected($selectedStatus === 'all')>All Status</option>
                    <option value="pending" @selected($selectedStatus === 'pending')>Pending</option>
                    <option value="accepted" @selected($selectedStatus === 'accepted')>Booked</option>
                    <option value="rejected" @selected($selectedStatus === 'rejected')>Rejected</option>
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
    @endif

    @if ($reservations->isEmpty())
        <section class="reservation-empty-card {{ $isAdmin ? 'admin-reservation-empty-card' : '' }}" aria-label="No reservations">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M8 2v4M16 2v4M3 10h18" />
                <rect x="3" y="4" width="18" height="18" rx="2" />
            </svg>
            <p>No reservations found</p>
            @unless ($isAdmin)
                <a class="browse-facilities-button" href="{{ route('facilities') }}">Browse Facilities</a>
            @endunless
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
                            <span class="reservation-status {{ $statusClass }}">{{ ! $isAdmin && $reservation->status === 'accepted' ? 'Booked' : ucfirst($reservation->status) }}</span>
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
                                {{ $reservation->period()->endDateLabel() }}
                            </span>
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg>
                                {{ $reservation->location }}
                            </span>
                            @if ($isAdmin && $reservation->requester_name)
                                <span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M20 21a8 8 0 0 0-16 0" />
                                        <circle cx="12" cy="7" r="4" />
                                    </svg>
                                    {{ $reservation->requester_name }}
                                </span>
                            @endif
                        </div>

                        <p>{{ $reservation->purpose }}</p>
                        @if ($isAdmin)
                            <details>
                                <summary>Resident contact information</summary>
                                <dl class="account-info-list">
                                    <div><dt>Resident/User</dt><dd>{{ $reservation->requester_name ?? 'Not available' }}</dd></div>
                                    <div><dt>Email</dt><dd>{{ $reservation->requester_email ?? 'Not available' }}</dd></div>
                                    <div><dt>Phone Number</dt><dd>{{ $reservation->requester_phone_number ?? 'Not provided' }}</dd></div>
                                </dl>
                            </details>
                        @endif
                        <p>Hourly Rate: {{ \App\Support\Money::format($reservation->hourly_rate_snapshot) }}{{ $reservation->hourly_rate_snapshot !== null ? ' / hour' : '' }}</p>
                        @if ($isAdmin)
                            <p>Duration: {{ $reservation->durationMinutes() }} minutes ({{ round($reservation->durationMinutes() / 60, 2) }} hours)</p>
                            <p>Calculated Amount: {{ \App\Support\Money::format($reservation->calculatedAmount()) }}</p>
                        @elseif ($reservation->status === 'accepted')
                            <p>Duration: {{ $reservation->durationMinutes() }} minutes ({{ round($reservation->durationMinutes() / 60, 2) }} hours)</p>
                            <p>Total Paid: {{ \App\Support\Money::format($reservation->total_payment) }}</p>
                        @endif
                    </div>

                    @if ($isAdmin)
                        <div class="admin-reservation-actions">
                            <form method="POST" action="{{ route('reservations.payment', $reservation) }}" class="reservation-group payment-form" id="payment-{{ $reservation->id }}">
                                @csrf
                                <label for="total-payment-{{ $reservation->id }}">Total Payment (₱)</label>
                                <input id="total-payment-{{ $reservation->id }}" name="total_payment" type="number" min="0" max="9999999999.99" step="0.01" value="{{ $reservation->total_payment ?? $reservation->calculatedAmount() }}" required>
                                <button type="submit" name="_method" value="PATCH" class="reservation-action-button">Save</button>
                                @if ($reservation->status === 'pending')
                                    <button type="button" data-confirm-payment data-accept-url="{{ route('reservations.accept', $reservation) }}" data-facility="{{ $reservation->facility_name }}" class="reservation-action-button reservation-accept-button">Accept</button>
                                @endif
                            </form>
                            @if ($reservation->status === 'pending')
                                <form method="POST" action="{{ route('reservations.reject', $reservation) }}">
                                    @csrf
                                    <button type="submit" class="reservation-action-button reservation-reject-button">Reject</button>
                                </form>
                            @endif
                            <a class="reservation-details-link" href="{{ route('facilities.show', $reservation->facility_slug) }}">View Facility</a>
                        </div>
                    @else
                        <a class="reservation-details-link" href="{{ route('facilities.show', $reservation->facility_slug) }}">View Facility</a>
                    @endif
                </article>
            @endforeach
        </section>
    @endif

    @if ($isAdmin)
        <dialog id="payment-confirmation" class="facility-modal-panel payment-confirmation" aria-labelledby="payment-confirmation-title" aria-describedby="payment-confirmation-description">
            <div class="facility-modal-header"><h3 id="payment-confirmation-title">Confirm Reservation</h3></div>
            <form method="POST" data-payment-confirmation-form>
                @csrf
                <input type="hidden" name="total_payment" data-confirmed-amount>
                <input type="hidden" name="payment_confirmed" value="1">
                <div class="facility-modal-body">
                    <p data-confirmed-facility></p>
                    <p id="payment-confirmation-description">By accepting this reservation, you are confirming that payment of <strong data-confirmed-amount-label></strong> has been received.</p>
                    <p>The reservation will be officially booked.</p>
                </div>
                <div class="facility-modal-actions">
                    <button type="button" class="facility-modal-secondary" data-cancel-payment autofocus>Cancel</button>
                    <button type="submit" class="facility-modal-primary">Confirm &amp; Accept</button>
                </div>
            </form>
        </dialog>
        <noscript><p class="reservation-alert">Enable JavaScript to review and confirm payment before accepting a reservation.</p></noscript>
        <script src="{{ asset('js/payment-confirmation.js') }}?v={{ filemtime(public_path('js/payment-confirmation.js')) }}" defer></script>
    @endif
    <script>
        document.querySelectorAll('[data-auto-submit]').forEach((select) => {
            select.addEventListener('change', () => select.form.submit());
        });
    </script>
</x-layouts.user>
