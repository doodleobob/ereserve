@php
    $resource = $residentResources->get($reservation->id);
    $payment = $residentPayments->get($reservation->id);
    $period = $reservation->period();
    $statusLabel = $reservation->status === 'accepted' ? 'Booked' : ucfirst($reservation->status);
    $managingBarangay = $resource?->barangay ?? $reservation->barangay;
    $canViewResource = $resource && (auth()->user()->role === 'user' || $resource->barangay === auth()->user()->barangay);
    $canEdit = $reservation->status === 'pending' && auth()->user()->can('editOwnRequest', $reservation)
        && $resource?->status === 'Available' && $resource->allowsReservationsBy(auth()->user());
    $totalLabel = $payment?->payment_status === 'paid' ? 'Total Paid' : 'Total Payment';
    $resourceType = in_array($reservation->category, ['Facility', 'Equipment'], true)
        ? $reservation->category : $resource?->category;
    $purposeLabel = filled($reservation->purpose) ? $reservation->purpose : 'Not recorded';
    $isOvernight = ! $period->start->isSameDay($period->end);
    $showPaymentStatus = $payment && ($reservation->status !== 'pending'
        || in_array($payment->payment_status, ['refund_pending', 'refunded'], true));
    $showPaymentTotal = $reservation->status === 'accepted' || $showPaymentStatus;
    $durationMinutes = $reservation->durationMinutes();
    $durationHours = intdiv($durationMinutes, 60);
    $remainingMinutes = $durationMinutes % 60;
    $durationParts = [];
    if ($durationHours > 0) {
        $durationParts[] = $durationHours . ($durationHours === 1 ? ' hour' : ' hours');
    }
    if ($remainingMinutes > 0 || $durationHours === 0) {
        $durationParts[] = $remainingMinutes . ($remainingMinutes === 1 ? ' minute' : ' minutes');
    }
    $durationLabel = implode(' ', $durationParts);
@endphp
<article class="reservation-list-card resident-reservation-card" id="resident-reservation-{{ $reservation->id }}">
    <header class="resident-card-header">
        <div class="resident-card-heading">
            <div class="resident-card-title"><h3>{{ $reservation->facility_name ?: 'Resource unavailable' }}</h3>@if($resourceType)<span class="resident-resource-type">{{ $resourceType }}</span>@endif</div>
            <p>Barangay {{ $managingBarangay ?: 'Not available' }} <span aria-hidden="true">&middot;</span> <span class="resident-reservation-id">Reservation #{{ $reservation->id }}</span></p>
        </div>
        <span class="reservation-status reservation-status-{{ strtolower($reservation->status) }}">{{ $statusLabel }}</span>
    </header>
    <dl class="resident-card-meta">
        @if($isOvernight)
            <div><dt>Start</dt><dd>{{ $period->start->format('F j, Y') }} <span class="resident-schedule-time">&mdash; {{ $period->start->format('g:i A') }}</span></dd></div>
            <div><dt>End</dt><dd>{{ $period->end->format('F j, Y') }} <span class="resident-schedule-time">&mdash; {{ $period->end->format('g:i A') }}</span></dd></div>
        @else
            <div><dt>Date</dt><dd>{{ $period->start->format('F j, Y') }}</dd></div>
            <div><dt>Time</dt><dd>{{ $period->start->format('g:i A') }} - {{ $period->end->format('g:i A') }}</dd></div>
        @endif
        <div><dt>Duration</dt><dd>{{ $durationLabel }}</dd></div>
        @if($resourceType === 'Equipment')
            <div><dt>Quantity Reserved</dt><dd>{{ $reservation->quantity !== null ? $reservation->quantity.($reservation->quantity == 1 ? ' unit' : ' units') : 'Not recorded (legacy booking)' }}</dd></div>
        @elseif($resourceType === 'Facility')
            <div><dt>Attendees</dt><dd>{{ $reservation->attendees ?? 'Not recorded' }}</dd></div>
        @endif
        <div class="resident-card-purpose"><dt>Purpose</dt><dd>{{ $purposeLabel }}</dd></div>
        @if($showPaymentTotal)
            <div><dt>{{ $totalLabel }}</dt><dd>{{ \App\Support\Money::format($reservation->total_payment) }}</dd></div>
        @endif
        @if($showPaymentStatus)
            <div><dt>Payment Status</dt><dd>{{ $payment->payment_status === 'pending' ? 'Payment Pending' : \App\Models\Payment::statusLabel($payment->payment_status) }}</dd></div>
        @endif
    </dl>
    @if($reservation->cancellation_reason)<p class="resident-card-purpose">Cancellation Reason: {{ $reservation->cancellation_reason }}</p>@endif
    @include('reservations.official-use-conflicts')
    <div class="resident-card-actions">
        <button type="button" class="facility-modal-primary" id="resident-details-button-{{ $reservation->id }}" data-modal-open="resident-details-{{ $reservation->id }}">View Details</button>
        @if($canViewResource)<a class="reservation-details-link" href="{{ route(\App\Support\FacilityCatalog::detailRoute($resource->category), $resource->slug) }}">{{ $resource->isEquipment() ? 'View Equipment' : 'View Facility' }}</a>@endif
        @if($canEdit)<button type="button" class="facility-modal-secondary" data-modal-open="resident-edit-{{ $reservation->id }}">Edit</button>@if($payment?->payment_status === 'refund_pending')<button type="button" data-reservation-open="payment-refund-{{ $payment->id }}">Confirm Refund</button>@endif @endif
    </div>
</article>
<x-modal id="resident-details-{{ $reservation->id }}" title="Reservation Details #{{ $reservation->id }}" size="medium" class="resident-reservation-dialog">
    <div class="facility-modal-body">
        <dl class="account-info-list">
            <div><dt>Reservation ID</dt><dd>#{{ $reservation->id }}</dd></div>
            <div><dt>Resource</dt><dd>{{ $reservation->facility_name ?: 'Not available' }}</dd></div>
            <div><dt>Managing Barangay</dt><dd>{{ $managingBarangay ?: 'Not available' }}</dd></div>
            <div><dt>Status</dt><dd>{{ $statusLabel }}</dd></div>
            <div><dt>Date</dt><dd>{{ $period->start->format('F j, Y') }}</dd></div>
            <div><dt>Start Time</dt><dd>{{ $period->start->format('g:i A') }}</dd></div>
            <div><dt>End Time</dt><dd>{{ $period->end->format('g:i A') }} {{ $period->endDateLabel() }}</dd></div>
            <div><dt>Duration</dt><dd>{{ $durationLabel }}</dd></div>
            <div><dt>Purpose</dt><dd class="resident-purpose-detail">{{ $purposeLabel }}</dd></div>
            @if($resourceType === 'Equipment')
                <div><dt>Quantity Requested</dt><dd>{{ $reservation->quantity ?? 'Whole-resource hold (legacy booking)' }}</dd></div>
            @else
                <div><dt>Attendees</dt><dd>{{ $reservation->attendees ?? 'Not recorded' }}</dd></div>
            @endif
            <div><dt>Location</dt><dd>{{ $reservation->location ?: 'Not recorded' }}</dd></div>
            <div><dt>Hourly Rate</dt><dd>{{ \App\Support\Money::format($reservation->hourly_rate_snapshot) }}{{ $reservation->hourly_rate_snapshot !== null ? ' / hour' : '' }}</dd></div>
            @if($showPaymentTotal)<div><dt>{{ $totalLabel }}</dt><dd>{{ \App\Support\Money::format($reservation->total_payment) }}</dd></div>@endif
            @if($showPaymentStatus)<div><dt>Payment Status</dt><dd>{{ $payment->payment_status === 'pending' ? 'Payment Pending' : \App\Models\Payment::statusLabel($payment->payment_status) }}</dd></div>@endif
            @if($reservation->cancellation_reason)
                <div><dt>Cancellation Reason</dt><dd>{{ $reservation->cancellation_reason }}</dd></div>
                <div><dt>Cancellation Notes</dt><dd>{{ $reservation->cancellation_notes ?: 'Not provided' }}</dd></div>
                <div><dt>Cancelled At</dt><dd>{{ $reservation->cancelled_at?->format('F j, Y g:i A') ?? 'Not recorded' }}</dd></div>
            @endif
            @foreach($reservation->officialUseConflicts as $conflict)
                <div><dt>Official Use #{{ $conflict->official_use_id }}</dt><dd>{{ $conflict->resolutionLabel() }}@if($conflict->officialUse)<br>{{ $conflict->officialUse->period()->start->format('M j, Y g:i A') }} – {{ $conflict->officialUse->period()->end->format('M j, Y g:i A') }}@endif</dd></div>
            @endforeach
        </dl>
    </div>
    <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Close</button></div>
</x-modal>
@if($canEdit)
    <x-modal id="resident-edit-{{ $reservation->id }}" title="Edit Reservation" size="medium" class="resident-reservation-dialog" aria-describedby="resident-edit-note-{{ $reservation->id }}">
        <form method="POST" action="{{ route('reservations.update-pending', $reservation) }}" data-future-reservation data-original-date="{{ $reservation->reservation_date }}" data-original-start="{{ $period->start->format('H:i') }}" data-original-end="{{ $period->end->format('H:i') }}" data-pending-reservation-action data-reservation-id="{{ $reservation->id }}" data-hourly-availability="{{ route('facilities.availability', $resource->slug) }}">
            @csrf @method('PATCH')
            <div class="facility-modal-body">
                <p id="resident-edit-note-{{ $reservation->id }}">You can update this request while it is pending. Changes will be reviewed by the barangay admin.</p>
                <p data-action-error role="alert" hidden></p>
                <label>Facility / Equipment<input type="text" value="{{ $reservation->facility_name }}" readonly></label>
                <label>Reservation Date<input type="date" name="reservation_date" value="{{ $period->start->toDateString() }}" min="{{ now('Asia/Manila')->toDateString() }}" required></label>
                <div class="resident-edit-times">
                    <label>Start Time<x-hour-select name="start_time" :value="$reservation->start_time" legacy /></label>
                    <label>End Time<x-hour-select name="end_time" :value="$reservation->end_time" legacy /></label>
                </div>
                <x-hourly-summary :next-day="! $period->start->isSameDay($period->end)" />
                <label>Purpose<textarea name="purpose" maxlength="500" rows="3" required>{{ $reservation->purpose }}</textarea></label>
                @if($resource->isEquipment())
                    <p>Total Quantity: {{ $resource->total_quantity ?? 'Awaiting stock verification' }}</p>
                    <p data-available-quantity aria-live="polite">Select a date and time to view Available Quantity.</p>
                    <label>Quantity Requested<input type="number" name="quantity" min="1" step="1" max="{{ $resource->total_quantity ?? 0 }}" value="{{ $reservation->quantity }}" @required($reservation->quantity !== null)></label>
                    @if($reservation->quantity === null)<p>Leave quantity blank to preserve this existing whole-resource hold.</p>@endif
                @else
                    <label>Number of Attendees<input type="number" name="attendees" min="1" max="{{ $resource->capacity }}" value="{{ $reservation->attendees }}" required></label>
                @endif
            </div>
            <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary">Save Changes</button></div>
        </form>
    </x-modal>
@endif
