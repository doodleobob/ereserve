@php
    $inModal = $inModal ?? false;
    $fieldPrefix = $inModal ? 'reserve-'.$facility['slug'].'-' : '';
@endphp

<form method="POST" action="{{ route('reservations.store', $facility['slug']) }}" class="reservation-form" data-future-reservation @if ($inModal) data-modal-action @endif>
    @csrf
    <div @class(['facility-modal-body' => $inModal, 'reservation-form-fields'])>
        @if ($inModal)
            <p class="reservation-selected-facility"><strong>{{ $facility['name'] }}</strong></p>
            <p data-action-error role="alert" hidden></p>
        @endif

        <div class="reservation-group">
            <label for="{{ $fieldPrefix }}reservation_date">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M8 2v4M16 2v4M3 10h18" />
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                </svg>
                Date <span>*</span>
            </label>
            <input id="{{ $fieldPrefix }}reservation_date" name="reservation_date" type="date" min="{{ now('Asia/Manila')->toDateString() }}" value="{{ old('reservation_date') }}" required>
            @error('reservation_date')
                <p class="form-error">{{ $message }}</p>
            @enderror
            @error('reservation')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

        <p class="reservation-time-help">If End Time is earlier than Start Time, the reservation ends the next day. Start and End Time must be different.</p>
        <div class="time-grid">
            <div class="reservation-group">
                <label for="{{ $fieldPrefix }}start_time">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v6l4 2" />
                    </svg>
                    Start Time <span>*</span>
                </label>
                <input id="{{ $fieldPrefix }}start_time" name="start_time" type="time" value="{{ old('start_time') }}" required>
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
                <input id="{{ $fieldPrefix }}end_time" name="end_time" type="time" value="{{ old('end_time') }}" required>
                @error('end_time')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="reservation-group">
            <label for="{{ $fieldPrefix }}purpose">Purpose / Event Name <span>*</span></label>
            <textarea id="{{ $fieldPrefix }}purpose" name="purpose" placeholder="Describe the purpose of your reservation" required>{{ old('purpose') }}</textarea>
            @error('purpose')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

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
            <input id="{{ $fieldPrefix }}attendees" name="attendees" type="number" min="1" max="{{ $facility['capacity'] }}" value="{{ old('attendees') }}" placeholder="Max capacity: {{ $facility['capacity'] }}" required>
            @error('attendees')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

    </div>
    <div @class(['facility-modal-actions' => $inModal, 'reservation-actions' => ! $inModal])>
        @if ($inModal)
            <button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button>
        @else
            <a class="cancel-reservation-button" href="{{ route('facilities') }}">Cancel</a>
        @endif
        <button type="submit" @class(['facility-modal-primary' => $inModal, 'submit-reservation-button' => ! $inModal])>Submit Reservation</button>
    </div>
</form>
