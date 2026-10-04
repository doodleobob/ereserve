<x-modal id="view-{{ $reservation->id }}" title="Reservation Details #{{ $reservation->id }}" size="medium">
    <div class="facility-modal-body">
        @include('reservations.official-use-conflicts')
        <dl class="account-info-list">
            <div><dt>Resident/User</dt><dd>{{ $reservation->requester_name ?? 'Not available' }}</dd></div>
            <div><dt>Home Barangay</dt><dd>{{ $reservation->requester_barangay ?? 'Not available' }}</dd></div>
            <div><dt>Email</dt><dd>{{ $reservation->requester_email ?? 'Not available' }}</dd></div>
            <div><dt>Phone Number</dt><dd>{{ $reservation->requester_phone_number ?? 'Not provided' }}</dd></div>
            <div><dt>Resource</dt><dd>{{ $reservation->facility_name }}</dd></div>
            <div><dt>Managing Barangay</dt><dd>{{ $reservation->managingBarangay() }}</dd></div>
            <div><dt>Date</dt><dd>{{ $reservation->period()->start->format('F j, Y') }}</dd></div>
            <div><dt>Start Time</dt><dd>{{ $reservation->period()->start->format('g:i A') }}</dd></div>
            <div><dt>End Time</dt><dd>{{ $reservation->period()->end->format('g:i A') }} {{ $reservation->period()->endDateLabel() }}</dd></div>
            <div><dt>Purpose</dt><dd>{{ $reservation->purpose }}</dd></div>
            <div><dt>Status</dt><dd>{{ ucfirst($reservation->status) }}</dd></div>
            <div><dt>Duration</dt><dd>{{ $reservation->durationMinutes() }} minutes</dd></div>
            <div><dt>Hourly Rate</dt><dd>{{ \App\Support\Money::format($reservation->hourly_rate_snapshot) }}{{ $reservation->hourly_rate_snapshot!==null ? ' / hour' : '' }}</dd></div>
            <div><dt>Calculated Amount</dt><dd>{{ \App\Support\Money::format($reservation->calculatedAmount()) }}</dd></div>
            <div><dt>Total Payment</dt><dd>{{ \App\Support\Money::format($reservation->total_payment) }}</dd></div>
            @if($reservation->cancellation_reason)
                <div><dt>Cancellation Reason</dt><dd>{{ $reservation->cancellation_reason }}</dd></div>
                <div><dt>Cancellation Notes</dt><dd>{{ $reservation->cancellation_notes ?? 'Not provided' }}</dd></div>
                <div><dt>Cancelled At</dt><dd>{{ $reservation->cancelled_at?->format('F j, Y g:i A') }}</dd></div>
            @endif
        </dl>
        @if($reservation->change_history)
            <h4>Change History</h4>
            <ul>
                @foreach($reservation->change_history as $change)
                    <li>{{ $change['action'] === 'cancel' ? 'Cancelled' : 'Rescheduled' }} by {{ $change['actor_name'] ?? 'Admin #'.$change['actor_id'] }} on {{ \Illuminate\Support\Carbon::parse($change['at'])->format('F j, Y g:i A') }}.
                        @if($change['action'] === 'reschedule')
                            Previous schedule: {{ $change['before']['reservation_date'] }} {{ substr($change['before']['start_time'],0,5) }} &ndash; {{ substr($change['before']['end_time'],0,5) }}.
                            New schedule: {{ $change['after']['reservation_date'] }} {{ substr($change['after']['start_time'],0,5) }} &ndash; {{ substr($change['after']['end_time'],0,5) }}.
                        @else
                            Reason: {{ $change['reason'] }}.
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
@can('manage',$reservation)
    @if($reservation->status==='pending')
        <form id="accept-source-{{ $reservation->id }}" hidden><input name="total_payment" value="{{ $reservation->total_payment ?? $reservation->calculatedAmount() ?? '' }}"></form>
        <x-modal id="reject-{{ $reservation->id }}" title="Reject Reservation #{{ $reservation->id }}" size="small">
            <form method="POST" action="{{ route('reservations.reject',$reservation) }}" data-reservation-action>@csrf
                <div class="facility-modal-body"><p>{{ $reservation->requester_name }} — {{ $reservation->facility_name }}</p><p>{{ $reservation->period()->start->format('F j, Y g:i A') }} – {{ $reservation->period()->end->format('F j, Y g:i A') }}</p><p data-action-error role="alert" hidden></p><label><input type="checkbox" name="rejection_confirmed" value="1" required> I confirm this pending reservation should be rejected.</label></div>
                <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary button-danger">Confirm Rejection</button></div>
            </form>
        </x-modal>
    @elseif($reservation->status==='accepted')
        <x-modal id="edit-{{ $reservation->id }}" title="Edit Accepted Reservation #{{ $reservation->id }}" size="medium" data-accepted-edit>
            <div class="facility-modal-body">
                @include('reservations.official-use-conflicts')
                <dl class="account-info-list">
                    <div><dt>Resident</dt><dd>{{ $reservation->requester_name }}</dd></div>
                    <div><dt>Resource</dt><dd>{{ $reservation->facility_name }}</dd></div>
                    <div><dt>Current Schedule</dt><dd>{{ $reservation->period()->start->format('F j, Y g:i A') }} &ndash; {{ $reservation->period()->end->format('F j, Y g:i A') }}</dd></div>
                    <div><dt>Status</dt><dd>Accepted</dd></div>
                    <div><dt>Total Payment</dt><dd>{{ \App\Support\Money::format($reservation->total_payment) }}</dd></div>
                </dl>
            </div>
            <div data-edit-step="choose" class="facility-modal-body">
                <p>Choose Action</p>
                <div class="facility-modal-actions"><button type="button" data-edit-choice="reschedule" class="facility-modal-primary">Reschedule Reservation</button><button type="button" data-edit-choice="cancel" class="button button-danger">Cancel Reservation</button></div>
            </div>
            <form method="POST" action="{{ route('reservations.edit-accepted',$reservation) }}" data-reservation-action data-edit-form>@csrf @method('PATCH')
                <input type="hidden" name="action" value="">
                <fieldset data-edit-step="reschedule" hidden disabled>
                    <div class="facility-modal-body">
                        <h4>Reschedule Reservation</h4>
                        <label>New Date<input type="date" name="reservation_date" min="{{ today()->toDateString() }}" value="{{ $reservation->reservation_date }}" required></label>
                        <label>Start Time<input type="time" name="start_time" value="{{ substr($reservation->start_time,0,5) }}" required></label>
                        <label>End Time<input type="time" name="end_time" value="{{ substr($reservation->end_time,0,5) }}" required></label>
                        <p>An earlier End Time ends the next day. Existing payment will be preserved.</p>
                    </div>
                    <div class="facility-modal-actions"><button type="button" data-edit-choice="choose" class="facility-modal-secondary">Back</button><button type="submit" class="facility-modal-primary">Save Reschedule</button></div>
                </fieldset>
                <fieldset data-edit-step="cancel" hidden disabled>
                    <div class="facility-modal-body">
                        <h4>Cancel Reservation</h4><p>Cancel this accepted reservation? Its recorded payment and history will be preserved. Cancellation does not record a refund.</p>
                        <label>Cancellation Reason<select name="cancellation_reason" required><option value="">Select a reason</option><option>User Requested Cancellation</option><option>Official Use</option><option>Other</option></select></label>
                        <label>Notes (optional)<textarea name="cancellation_notes" maxlength="1000" rows="3"></textarea></label>
                        <label><input type="checkbox" name="cancellation_confirmed" value="1" required> I confirm this accepted reservation should be cancelled.</label>
                    </div>
                    <div class="facility-modal-actions"><button type="button" data-edit-choice="choose" class="facility-modal-secondary">Back</button><button type="submit" class="facility-modal-primary button-danger">Confirm Cancellation</button></div>
                </fieldset>
                <div class="facility-modal-body" data-edit-error><p data-action-error role="alert" hidden></p></div>
            </form>
        </x-modal>
    @endif
@endcan
