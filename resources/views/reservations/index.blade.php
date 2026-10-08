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

    @error('decision')<div class="reservation-alert reservation-alert-error" role="alert">{{ $message }}</div>@enderror
    @error('total_payment')
        <div class="reservation-alert reservation-alert-error" role="alert">{{ $message }}</div>
    @enderror
    @error('payment_confirmed')
        <div class="reservation-alert reservation-alert-error" role="alert">{{ $message }}</div>
    @enderror

    @if ($isAdmin)<div id="reservation-management" data-reservation-table data-page="{{ $reservations->currentPage() }}">@endif
    @if ($isAdmin)
        <form method="GET" action="{{ route('reservations.index') }}" class="filter-card filter-toolbar admin-reservation-filter-card" id="reservation-filters" aria-label="Reservation filters">
            @foreach(['sort','direction','per_page','reservation'] as $key) @if(request()->filled($key) && $key!=='per_page')<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif @endforeach
            <div class="filter-group admin-search-group">
                <label for="search">Search</label>
                <div class="search-field">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.35-4.35" />
                    </svg>
                    <input id="search" name="search" type="search" value="{{ $selectedSearch }}" placeholder="Search ID, resident, resource, or purpose...">
                </div>
            </div>

            <div class="filter-group">
                <label for="status">Status</label>
                <select id="status" name="status" data-auto-submit>
                    <option value="all" @selected($selectedStatus === 'all')>All Status</option>
                    <option value="pending" @selected($selectedStatus === 'pending')>Pending</option>
                    <option value="accepted" @selected($selectedStatus === 'accepted')>Accepted</option>
                    <option value="rejected" @selected($selectedStatus === 'rejected')>Rejected</option><option value="cancelled" @selected($selectedStatus === 'cancelled')>Cancelled</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="conflict">Conflict</label>
                <select id="conflict" name="conflict" data-auto-submit>
                    <option value="all">All</option>
                    <option value="official_use" @selected(request('conflict') === 'official_use')>Official Use Conflict</option>
                    <option value="none" @selected(request('conflict') === 'none')>No Conflict</option>
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
            <div class="filter-actions"><button type="submit" class="reservation-table-search">Search</button></div>
        </form>
    @else
        <form method="GET" action="{{ route('reservations.index') }}" class="filter-card filter-toolbar reservation-filter-card" aria-label="Reservation filters">
            <div class="filter-group">
                <label for="status">Filter by Status</label>
                <select id="status" name="status" data-auto-submit>
                    <option value="all" @selected($selectedStatus === 'all')>All Status</option>
                    <option value="pending" @selected($selectedStatus === 'pending')>Pending</option>
                    <option value="accepted" @selected($selectedStatus === 'accepted')>Booked</option>
                    <option value="rejected" @selected($selectedStatus === 'rejected')>Rejected</option>
                    <option value="cancelled" @selected($selectedStatus === 'cancelled')>Cancelled</option>
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

    @if($isAdmin)
        @include('reservations.admin-table')
    @else
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
                            <p>Managing Barangay: {{ $reservation->managingBarangay() }}</p>
                            <span class="reservation-status {{ $statusClass }}">{{ $reservation->status === 'accepted' ? 'Booked' : ucfirst($reservation->status) }}</span>
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
                        </div>

                        <p>{{ $reservation->purpose }}</p>
                        @include('reservations.official-use-conflicts')
                        @if($reservation->cancellation_reason)<p>Cancellation Reason: {{ $reservation->cancellation_reason }}</p>@endif
                        <p>Hourly Rate: {{ \App\Support\Money::format($reservation->hourly_rate_snapshot) }}{{ $reservation->hourly_rate_snapshot !== null ? ' / hour' : '' }}</p>
                        @if ($reservation->status === 'accepted')
                            <p>Duration: {{ $reservation->durationMinutes() }} minutes ({{ round($reservation->durationMinutes() / 60, 2) }} hours)</p>
                            <p>Total Paid: {{ \App\Support\Money::format($reservation->total_payment) }}</p>
                        @endif
                    </div>

                    <a class="reservation-details-link" href="{{ route('facilities.show', $reservation->facility_slug) }}">View Facility</a>

                </article>
            @endforeach
        </section>
    @endif

    @endif

    @if ($isAdmin)
        <x-modal id="payment-confirmation" title="Confirm Reservation" size="medium" aria-describedby="payment-confirmation-description">
            <form method="POST" data-payment-confirmation-form data-reservation-action>
                @csrf
                <label class="reservation-confirmed-total">Total Payment (&#8369;)<input type="number" name="total_payment" min="0" max="9999999999.99" step="0.01" required data-confirmed-amount></label>
                <input type="hidden" name="payment_confirmed" value="1">
                <div class="facility-modal-body">
                    <p data-confirmed-resident></p><p data-confirmed-facility></p><p data-confirmed-schedule></p><p data-action-error role="alert" hidden></p>
                    <p id="payment-confirmation-description">By accepting this reservation, you are confirming that payment of <strong data-confirmed-amount-label></strong> has been received.</p>
                    <p>The reservation will be officially booked.</p>
                </div>
                <div class="facility-modal-actions">
                    <button type="button" class="facility-modal-secondary" data-cancel-payment autofocus>Cancel</button>
                    <button type="submit" class="facility-modal-primary">Confirm &amp; Accept</button>
                </div>
            </form>
        </x-modal>
        <noscript><p class="reservation-alert">Enable JavaScript to review and confirm payment before accepting a reservation.</p></noscript>
        </div>
        @push('scripts')
            <script src="{{ asset('js/reservation-datatable.js') }}?v={{ filemtime(public_path('js/reservation-datatable.js')) }}" defer></script>
        @endpush
        @push('scripts')
            <script src="{{ asset('js/payment-confirmation.js') }}?v={{ filemtime(public_path('js/payment-confirmation.js')) }}" defer></script>
        @endpush
    @endif
    @unless($isAdmin)
    @push('scripts')
        <script src="{{ asset('js/page-filters.js') }}?v={{ filemtime(public_path('js/page-filters.js')) }}" defer></script>
    @endpush
    @endunless
</x-layouts.user>
