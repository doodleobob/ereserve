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
        <div><h3>{{ $reservation->facility_name ?: 'Resource unavailable' }}</h3><p>Barangay {{ $managingBarangay ?: 'Not available' }} <span class="resident-reservation-id">Reservation #{{ $reservation->id }}</span></p></div>
        <span class="reservation-status reservation-status-{{ strtolower($reservation->status) }}">{{ $statusLabel }}</span>
    </header>
    <div class="resident-card-meta">
        <p><span>Date</span><strong>{{ $period->start->format('M j, Y') }}</strong></p>
        <p><span>Time</span><strong>{{ $period->start->format('g:i A') }} - {{ $period->end->format('g:i A') }}</strong>@if($period->endDateLabel())<small>{{ $period->endDateLabel() }}</small>@endif</p>
        <p>Duration: {{ $durationLabel }}</p>
        @if($reservation->status === 'accepted')<p>{{ $totalLabel }}: {{ \App\Support\Money::format($reservation->total_payment) }}</p>@endif
    </div>
    <p class="resident-card-purpose">{{ $reservation->purpose ?: 'Purpose not recorded' }}</p>
    @if($reservation->cancellation_reason)<p class="resident-card-purpose">Cancellation Reason: {{ $reservation->cancellation_reason }}</p>@endif
    @include('reservations.official-use-conflicts')
    <div class="resident-card-actions">
        <button type="button" class="facility-modal-primary" id="resident-details-button-{{ $reservation->id }}" data-modal-open="resident-details-{{ $reservation->id }}">View Details</button>
        @if($canViewResource)<a class="reservation-details-link" href="{{ route('facilities.show', $resource->slug) }}">View Facility</a>@endif
        @if($canEdit)<button type="button" class="facility-modal-secondary" data-modal-open="resident-edit-{{ $reservation->id }}">Edit</button>@endif
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
            <div><dt>Purpose</dt><dd class="resident-purpose-detail">{{ $reservation->purpose ?: 'Not recorded' }}</dd></div>
            <div><dt>Attendees</dt><dd>{{ $reservation->attendees ?? 'Not recorded' }}</dd></div>
            <div><dt>Location</dt><dd>{{ $reservation->location ?: 'Not recorded' }}</dd></div>
            <div><dt>Hourly Rate</dt><dd>{{ \App\Support\Money::format($reservation->hourly_rate_snapshot) }}{{ $reservation->hourly_rate_snapshot !== null ? ' / hour' : '' }}</dd></div>
            @if($reservation->status === 'accepted')<div><dt>{{ $totalLabel }}</dt><dd>{{ \App\Support\Money::format($reservation->total_payment) }}</dd></div>@endif
            @if($reservation->status !== 'pending' && $payment)<div><dt>Payment Status</dt><dd>{{ ucfirst($payment->payment_status) }}</dd></div>@endif
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
        <form method="POST" action="{{ route('reservations.update-pending', $reservation) }}" data-future-reservation data-pending-reservation-action data-reservation-id="{{ $reservation->id }}">
            @csrf @method('PATCH')
            <div class="facility-modal-body">
                <p id="resident-edit-note-{{ $reservation->id }}">You can update this request while it is pending. Changes will be reviewed by the barangay admin.</p>
                <p data-action-error role="alert" hidden></p>
                <label>Facility / Equipment<input type="text" value="{{ $reservation->facility_name }}" readonly></label>
                <label>Reservation Date<input type="date" name="reservation_date" value="{{ $period->start->toDateString() }}" min="{{ now('Asia/Manila')->toDateString() }}" required></label>
                <div class="resident-edit-times">
                    <label>Start Time<input type="time" name="start_time" value="{{ $period->start->format('H:i') }}" required></label>
                    <label>End Time<input type="time" name="end_time" value="{{ $period->end->format('H:i') }}" required aria-describedby="resident-edit-time-note-{{ $reservation->id }}"></label>
                </div>
                <p id="resident-edit-time-note-{{ $reservation->id }}">An earlier End Time ends the next day. Start and end times must be different.</p>
                <label>Purpose<textarea name="purpose" maxlength="500" rows="3" required>{{ $reservation->purpose }}</textarea></label>
                <label>Number of Attendees<input type="number" name="attendees" min="1" max="{{ $resource->capacity }}" value="{{ $reservation->attendees }}" required></label>
            </div>
            <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary">Save Changes</button></div>
        </form>
    </x-modal>
@endif
