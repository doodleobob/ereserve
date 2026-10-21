@php
    $unifiedBooking = $unifiedBooking ?? false;
    $inModal = $inModal ?? false;
    $fieldPrefix = $inModal ? 'reserve-'.$facility['slug'].'-' : '';
    $bookingValue = static function (string $name, mixed $default = '') {
        $value = old($name, $default);
        return is_scalar($value) ? $value : '';
    };
@endphp

<form method="POST" action="{{ route('reservations.store', $facility['slug']) }}" class="reservation-form" data-future-reservation @if ($unifiedBooking) data-unified-booking @endif data-hourly-availability="{{ route('facilities.availability', $facility['slug']) }}" @if ($inModal) data-modal-action @endif>
    @csrf
    <div @class(['facility-modal-body' => $inModal, 'reservation-form-fields'])>
        @if ($inModal)
            <p class="reservation-selected-facility"><strong>{{ $facility['name'] }}</strong></p>
            <p data-action-error role="alert" hidden></p>
        @endif

        @if ($unifiedBooking)<h3>Choose Your Schedule</h3>@endif
        <div class="reservation-group">
            <label for="{{ $fieldPrefix }}reservation_date">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M8 2v4M16 2v4M3 10h18" />
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                </svg>
                Date <span>*</span>
            </label>
            <input id="{{ $fieldPrefix }}reservation_date" name="reservation_date" type="date" min="{{ now('Asia/Manila')->toDateString() }}" value="{{ $bookingValue('reservation_date', $unifiedBooking ? $selectedDate->toDateString() : '') }}" required>
            @error('reservation_date')
                <p class="form-error">{{ $message }}</p>
            @enderror
            @error('reservation')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="time-grid">
            <div class="reservation-group">
                <label for="{{ $fieldPrefix }}start_time">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v6l4 2" />
                    </svg>
                    Start Time <span>*</span>
                </label>
                <x-hour-select name="start_time" :id="$fieldPrefix.'start_time'" :value="$bookingValue('start_time', $unifiedBooking ? request('start_time', '') : '')" />
                @error('start_time')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="reservation-group">
                <label for="{{ $fieldPrefix }}end_time">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v6l4 2" />
                    </svg>
                    End Time <span>*</span>
                </label>
                <x-hour-select name="end_time" :id="$fieldPrefix.'end_time'" :value="$bookingValue('end_time', $unifiedBooking ? request('end_time', '') : '')" />
                @error('end_time')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <x-hourly-summary :next-day="in_array($bookingValue('end_next_day', $unifiedBooking && request()->boolean('end_next_day')), [true, 1, '1'], true)" :label="$unifiedBooking ? 'Reservation Ends' : 'End day'" />
        @error('end_next_day')<p class="form-error" role="alert">{{ $message }}</p>@enderror
        @if ($unifiedBooking)<h3>Reservation Details</h3>@endif

        @if ($facility['category'] === 'Equipment')
        <div class="reservation-group">
            <label for="{{ $fieldPrefix }}quantity">Quantity Requested <span>*</span></label>
            <p>Total Quantity: {{ $facility['total_quantity'] ?? 'Awaiting stock verification' }}</p>
            <p data-available-quantity aria-live="polite">Select a date and time to view Available Quantity.</p>
            <input id="{{ $fieldPrefix }}quantity" name="quantity" type="number" min="1" step="1" max="{{ $facility['total_quantity'] ?? 0 }}" value="{{ $bookingValue('quantity', $unifiedBooking ? request('quantity', 1) : request('quantity')) }}" required>
            @error('quantity')<p class="form-error" role="alert">{{ $message }}</p>@enderror
        </div>
        @endif
        <div class="reservation-group">
            <label for="{{ $fieldPrefix }}purpose">Purpose / Event Name <span>*</span></label>
            <textarea id="{{ $fieldPrefix }}purpose" name="purpose" placeholder="Describe the purpose of your reservation" maxlength="500" required>{{ $bookingValue('purpose') }}</textarea>
            @error('purpose')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

        @if ($facility['category'] !== 'Equipment')
        <div class="reservation-group">
            <label for="{{ $fieldPrefix }}attendees">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                Expected Number of Attendees <span>*</span>
            </label>
            <input id="{{ $fieldPrefix }}attendees" name="attendees" type="number" min="1" max="{{ $facility['capacity'] }}" value="{{ $bookingValue('attendees') }}" placeholder="Max capacity: {{ $facility['capacity'] }}" required>
            @error('attendees')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>
        @endif

        @if ($unifiedBooking)
            <section class="booking-summary" aria-label="Booking Summary">
                <h3>Booking Summary</h3>
                <dl>
                    <div><dt>Resource</dt><dd>{{ $facility['name'] }}</dd></div>
                    <div><dt>Barangay</dt><dd>{{ $facility['barangay'] }}</dd></div>
                    <div><dt>Start</dt><dd data-booking-start>Select a schedule</dd></div>
                    <div><dt>End</dt><dd data-booking-end>Select a schedule</dd></div>
                    <div><dt>Duration</dt><dd data-booking-duration>&mdash;</dd></div>
                    @if ($facility['category'] === 'Equipment')
                        <div><dt>Quantity requested</dt><dd data-booking-quantity>&mdash;</dd></div>
                    @endif
                    <div><dt>Hourly rate</dt><dd data-booking-rate>{{ \App\Support\Money::format($facility['hourly_rate']) }} / hour</dd></div>
                    <div><dt>Estimated total</dt><dd data-booking-total>&mdash;</dd></div>
                </dl>
                <p data-booking-status role="status" aria-live="polite">Choose your schedule to check availability.</p>
                <button type="button" class="cancel-reservation-button" data-booking-retry hidden>Retry availability check</button>
            </section>
            <noscript><p class="form-error">Please enable JavaScript to check availability and submit your reservation.</p></noscript>
        @endif
    </div>
    <div @class(['facility-modal-actions' => $inModal, 'reservation-actions' => ! $inModal])>
        @if ($inModal)
            <button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button>
        @else
            <a class="cancel-reservation-button" href="{{ route($facility['category'] === 'Equipment' ? 'equipment' : 'facilities') }}">Cancel</a>
        @endif
        <button type="submit" @if ($unifiedBooking) disabled @endif @class(['facility-modal-primary' => $inModal, 'submit-reservation-button' => ! $inModal])>Submit Reservation</button>
    </div>
</form>
