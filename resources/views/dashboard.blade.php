<x-layouts.user title="Reservation Calendar - eReserve" active="dashboard">
    @php
        $selectedFacilitySlug = $selectedFacility['slug'];
        $selectedDateValue = $selectedDate->toDateString();
        $baseQuery = ['facility' => $selectedFacilitySlug, 'month' => $month->format('Y-m')];
        $statusLabels = [
            'available' => 'Available',
            'partial' => 'Partially booked',
            'full' => 'Fully booked',
            'unavailable' => 'Unavailable',
        ];
    @endphp

    @if ($isAdmin)
        <section class="welcome-section">
            <h2>Welcome, {{ auth()->user()->name }}</h2>
            <p>Overview of available facilities and your reservations</p>
        </section>

        <section class="stats-grid" aria-label="Reservation summary">
            <article class="stat-card stat-blue">
                <div>
                    <span>Available Facilities</span>
                    <strong>{{ $facilityCount }}</strong>
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
                    <strong>{{ $pendingCount }}</strong>
                </div>
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7v6l4 2" />
                </svg>
            </article>

            <article class="stat-card stat-green">
                <div>
                    <span>Approved Reservations</span>
                    <strong>{{ $approvedCount }}</strong>
                </div>
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="m8 12 3 3 6-7" />
                </svg>
            </article>

            <article class="stat-card stat-red">
                <div>
                    <span>Declined Requests</span>
                    <strong>{{ $declinedCount }}</strong>
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
                @if ($recentReservations->isEmpty())
                    <p>No reservations yet</p>
                @else
                    <div class="recent-reservation-list">
                        @foreach ($recentReservations as $reservation)
                            @php
                                $reservationDate = \Illuminate\Support\Carbon::parse($reservation->reservation_date);
                                $statusClass = 'reservation-status-' . strtolower($reservation->status);
                            @endphp

                            <a class="recent-reservation-item" href="{{ route('reservations.index') }}">
                                <span>
                                    <strong>{{ $reservation->facility_name }}</strong>
                                    <small>{{ $reservationDate->format('M j, Y') }} &middot; {{ $reservation->user?->name ?? 'Unknown user' }}</small>
                                </span>
                                <span class="reservation-status {{ $statusClass }}">{{ ucfirst($reservation->status) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </article>
        </section>
    @else
    <section class="page-heading reservations-heading">
        <h2>Reservation Calendar</h2>
        <p>Select a facility, choose a date, and reserve an available time slot</p>
    </section>

    @if (session('reservation_status'))
        <div class="reservation-alert" role="status">
            {{ session('reservation_status') }}
        </div>
    @endif

    <form method="GET" action="{{ route('dashboard') }}" class="filter-card calendar-filter-card" aria-label="Calendar filters">
        <div class="filter-group">
            <label for="facility">Facility</label>
            <select id="facility" name="facility" data-auto-submit>
                @foreach ($facilities as $facility)
                    <option value="{{ $facility['slug'] }}" @selected($facility['slug'] === $selectedFacilitySlug)>
                        {{ $facility['name'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="filter-group">
            <label for="date">Selected Date</label>
            <input id="date" name="date" type="date" value="{{ $selectedDateValue }}" data-auto-submit>
        </div>

        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
    </form>

    <section class="calendar-layout">
        <article class="content-card calendar-card">
            <div class="calendar-header">
                <a class="calendar-nav-button" href="{{ route('dashboard', ['facility' => $selectedFacilitySlug, 'month' => $previousMonth->format('Y-m')]) }}" aria-label="Previous month">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                </a>

                <h3>{{ $month->format('F Y') }}</h3>

                <a class="calendar-nav-button" href="{{ route('dashboard', ['facility' => $selectedFacilitySlug, 'month' => $nextMonth->format('Y-m')]) }}" aria-label="Next month">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </a>
            </div>

            <div class="calendar-weekdays" aria-hidden="true">
                <span>Sun</span>
                <span>Mon</span>
                <span>Tue</span>
                <span>Wed</span>
                <span>Thu</span>
                <span>Fri</span>
                <span>Sat</span>
            </div>

            <div class="calendar-grid" aria-label="{{ $month->format('F Y') }} availability">
                @for ($blank = 0; $blank < $month->copy()->startOfMonth()->dayOfWeek; $blank++)
                    <span class="calendar-day calendar-day-empty" aria-hidden="true"></span>
                @endfor

                @foreach ($calendarDays as $day)
                    @php
                        $dateValue = $day['date']->toDateString();
                        $status = $day['status'];
                        $isSelected = $dateValue === $selectedDateValue;
                    @endphp

                    <a
                        class="calendar-day calendar-day-{{ $status }} {{ $isSelected ? 'selected' : '' }}"
                        href="{{ route('dashboard', $baseQuery + ['date' => $dateValue]) }}"
                        aria-label="{{ $day['date']->format('F j, Y') }}: {{ $statusLabels[$status] }}"
                    >
                        <strong>{{ $day['date']->day }}</strong>
                        <span>{{ $statusLabels[$status] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="calendar-legend" aria-label="Availability legend">
                @foreach ($statusLabels as $status => $label)
                    <span class="legend-item legend-{{ $status }}">{{ $label }}</span>
                @endforeach
            </div>
        </article>

        <aside class="content-card day-schedule-card">
            <h3>{{ $selectedDate->format('F j, Y') }}</h3>
            <p class="schedule-facility-name">{{ $selectedFacility['name'] }}</p>

            <div class="schedule-list" aria-label="Daily schedule">
                @foreach ($schedule as $slot)
                    @php
                        $slotQuery = $baseQuery + [
                            'date' => $selectedDateValue,
                            'start_time' => $slot['start_time'],
                            'end_time' => $slot['end_time'],
                        ];
                        $isActiveSlot = $selectedSlot
                            && $selectedSlot['start_time'] === $slot['start_time']
                            && $selectedSlot['end_time'] === $slot['end_time'];
                    @endphp

                    @if ($slot['status'] === 'available')
                        <a class="schedule-slot schedule-slot-available {{ $isActiveSlot ? 'selected' : '' }}" href="{{ route('dashboard', $slotQuery) }}">
                            <span>{{ $slot['label'] }}</span>
                            <strong>Available</strong>
                        </a>
                    @else
                        <span class="schedule-slot schedule-slot-{{ $slot['status'] }}" aria-disabled="true">
                            <span>{{ $slot['label'] }}</span>
                            <strong>{{ ucfirst($slot['status']) }}</strong>
                        </span>
                    @endif
                @endforeach
            </div>
        </aside>
    </section>

    <section class="facility-detail-grid calendar-reservation-grid">
        <article class="facility-detail-card">
            <div class="facility-detail-body">
                <h2>{{ $selectedFacility['name'] }}</h2>
                <span class="availability-badge">{{ $selectedFacility['status'] }}</span>
                <p class="facility-detail-description">{{ $selectedFacility['description'] }}</p>

                <dl class="facility-info-list">
                    <div>
                        <dt>
                            <span class="info-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                                    <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                                    <path d="M10 6h4M10 10h4M10 14h4" />
                                </svg>
                            </span>
                            Category
                        </dt>
                        <dd>{{ $selectedFacility['category'] }}</dd>
                    </div>

                    <div>
                        <dt>
                            <span class="info-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg>
                            </span>
                            Location
                        </dt>
                        <dd>{{ $selectedFacility['location'] }}</dd>
                    </div>
                </dl>
            </div>
        </article>

        <article class="reservation-card">
            <h2>Reserve Selected Time</h2>

            @if ($selectedSlot)
                <form method="POST" action="{{ route('reservations.store', $selectedFacilitySlug) }}" class="reservation-form">
                    @csrf
                    <input type="hidden" name="reservation_date" value="{{ $selectedDateValue }}">
                    <input type="hidden" name="start_time" value="{{ $selectedSlot['start_time'] }}">
                    <input type="hidden" name="end_time" value="{{ $selectedSlot['end_time'] }}">

                    <div class="selected-slot-summary">
                        <span>{{ $selectedDate->format('M j, Y') }}</span>
                        <strong>{{ $selectedSlot['label'] }}</strong>
                    </div>

                    @error('reservation_date')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                    @error('start_time')
                        <p class="form-error">{{ $message }}</p>
                    @enderror

                    <div class="reservation-group">
                        <label for="purpose">Purpose / Event Name <span>*</span></label>
                        <textarea id="purpose" name="purpose" placeholder="Describe the purpose of your reservation" required>{{ old('purpose') }}</textarea>
                        @error('purpose')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="reservation-group">
                        <label for="attendees">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </svg>
                            Expected Number of Attendees <span>*</span>
                        </label>
                        <input id="attendees" name="attendees" type="number" min="1" max="{{ $selectedFacility['capacity'] }}" value="{{ old('attendees') }}" placeholder="Max capacity: {{ $selectedFacility['capacity'] }}" required>
                        @error('attendees')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="reservation-actions">
                        <button type="submit" class="submit-reservation-button">Submit Reservation</button>
                        <a class="cancel-reservation-button" href="{{ route('dashboard', $baseQuery + ['date' => $selectedDateValue]) }}">Clear</a>
                    </div>
                </form>
            @else
                <div class="reservation-empty-panel">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M8 2v4M16 2v4M3 10h18" />
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                    </svg>
                    <p>Select an available time slot from the schedule.</p>
                </div>
            @endif
        </article>
    </section>

    @if (auth()->user()->role === 'admin')
        <section class="content-card admin-schedule-card">
            <div class="admin-schedule-header">
                <h3>Admin Schedule</h3>

                <form method="GET" action="{{ route('dashboard') }}" class="admin-schedule-filters">
                    <input type="hidden" name="facility" value="{{ $selectedFacilitySlug }}">
                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                    <input type="hidden" name="date" value="{{ $selectedDateValue }}">

                    <div class="filter-group">
                        <label for="admin_date">Date</label>
                        <input id="admin_date" name="admin_date" type="date" value="{{ $selectedAdminDate }}" data-auto-submit>
                    </div>

                    <div class="filter-group">
                        <label for="admin_status">Status</label>
                        <select id="admin_status" name="admin_status" data-auto-submit>
                            <option value="all" @selected($selectedAdminStatus === 'all')>All Status</option>
                            <option value="pending" @selected($selectedAdminStatus === 'pending')>Pending</option>
                            <option value="approved" @selected($selectedAdminStatus === 'approved')>Approved</option>
                            <option value="declined" @selected($selectedAdminStatus === 'declined')>Declined</option>
                            <option value="cancelled" @selected($selectedAdminStatus === 'cancelled')>Cancelled</option>
                            <option value="rejected" @selected($selectedAdminStatus === 'rejected')>Rejected</option>
                        </select>
                    </div>
                </form>
            </div>

            @if ($adminReservations->isEmpty())
                <p class="admin-empty-state">No reservations match the selected filters.</p>
            @else
                <div class="admin-reservation-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Facility</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>User</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($adminReservations as $reservation)
                                @php
                                    $reservationDate = \Illuminate\Support\Carbon::parse($reservation->reservation_date);
                                    $startTime = \Illuminate\Support\Carbon::parse($reservation->start_time)->format('g:i A');
                                    $endTime = \Illuminate\Support\Carbon::parse($reservation->end_time)->format('g:i A');
                                @endphp
                                <tr>
                                    <td>{{ $reservation->facility_name }}</td>
                                    <td>{{ $reservationDate->format('M j, Y') }}</td>
                                    <td>{{ $startTime }} - {{ $endTime }}</td>
                                    <td>{{ $reservation->user?->name ?? 'Unknown user' }}</td>
                                    <td><span class="reservation-status reservation-status-{{ strtolower($reservation->status) }}">{{ ucfirst($reservation->status) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
    @endif

    <script>
        document.querySelectorAll('[data-auto-submit]').forEach((field) => {
            field.addEventListener('change', () => field.form.submit());
        });
    </script>
</x-layouts.user>
