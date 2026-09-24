<x-layouts.user title="{{ $facility['name'] }} - eReserve" active="facilities">
    <a class="back-link" href="{{ route('facilities') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="m12 19-7-7 7-7" />
            <path d="M19 12H5" />
        </svg>
        Back to Facilities
    </a>

    @if (session('reservation_status'))
        <div class="reservation-alert" role="status">
            {{ session('reservation_status') }}
        </div>
    @endif

    <section class="facility-detail-grid">
        <article class="facility-detail-card">
            @include('partials.facility-gallery', ['facility' => $facility])

            <div class="facility-detail-body">
                <h2>{{ $facility['name'] }}</h2>
                <span class="availability-badge availability-badge-{{ \Illuminate\Support\Str::slug($facility['display_status']) }}">
                    {{ $facility['display_status'] }}
                </span>
                @if ($facility['current_reservation'])
                    <p class="facility-detail-description">
                        {{ $facility['current_reservation']['start_time'] }} - {{ $facility['current_reservation']['end_time'] }}
                    </p>
                @endif
                <p class="facility-detail-description">{{ $facility['description'] }}</p>

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
                        <dd>{{ $facility['category'] }}</dd>
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
                        <dd>{{ $facility['location'] }}</dd>
                    </div>

                    <div>
                        <dt>
                            <span class="info-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                    <circle cx="9" cy="7" r="4" />
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                </svg>
                            </span>
                            Capacity
                        </dt>
                        <dd>{{ $facility['capacity'] }} {{ $facility['capacity'] == 1 ? 'person' : 'persons' }}</dd>
                    </div>
                </dl>
            </div>
        </article>

        <article class="reservation-card">
            <h2>Reserve This Facility</h2>

            @if ($facility['is_available'])
            <form method="POST" action="{{ route('reservations.store', $facility['slug']) }}" class="reservation-form">
                @csrf

                <div class="reservation-group">
                    <label for="reservation_date">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M8 2v4M16 2v4M3 10h18" />
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                        </svg>
                        Date <span>*</span>
                    </label>
                    <input id="reservation_date" name="reservation_date" type="date" value="{{ old('reservation_date') }}" required>
                    @error('reservation_date')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                    @error('reservation')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="time-grid">
                    <div class="reservation-group">
                        <label for="start_time">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v6l4 2" />
                            </svg>
                            Start Time <span>*</span>
                        </label>
                        <input id="start_time" name="start_time" type="time" value="{{ old('start_time') }}" required>
                        @error('start_time')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="reservation-group">
                        <label for="end_time">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v6l4 2" />
                            </svg>
                            End Time <span>*</span>
                        </label>
                        <input id="end_time" name="end_time" type="time" value="{{ old('end_time') }}" required>
                        @error('end_time')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

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
                    <input id="attendees" name="attendees" type="number" min="1" max="{{ $facility['capacity'] }}" value="{{ old('attendees') }}" placeholder="Max capacity: {{ $facility['capacity'] }}" required>
                    @error('attendees')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="reservation-actions">
                    <button type="submit" class="submit-reservation-button">Submit Reservation</button>
                    <a class="cancel-reservation-button" href="{{ route('facilities') }}">Cancel</a>
                </div>
            </form>
            @else
                <div class="reservation-empty-panel reservation-unavailable-panel">
                    <p>This facility is currently unavailable for reservations.</p>
                </div>
            @endif
        </article>
    </section>
</x-layouts.user>
