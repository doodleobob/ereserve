<x-modal id="official-use-view-{{ $use->id }}" title="Official Use #{{ $use->id }}" size="medium">
    <div class="facility-modal-body">
        <dl class="account-info-list">
            <div><dt>Official Use ID</dt><dd>#{{ $use->id }}</dd></div>
            <div><dt>Resource</dt><dd>{{ $use->facility->name }}</dd></div>
            @if($use->facility->isEquipment())<div><dt>Quantity Requested</dt><dd>{{ $use->quantity ?? 'Whole-resource hold (legacy booking)' }}</dd></div>@endif
            <div><dt>Date</dt><dd>{{ $use->period()->start->format('F j, Y') }}</dd></div>
            <div><dt>Start Time</dt><dd>{{ $use->period()->start->format('g:i A') }}</dd></div>
            <div><dt>End Time</dt><dd>{{ $use->period()->end->format('g:i A') }} {{ $use->period()->endDateLabel() }}</dd></div>
            <div><dt>Purpose</dt><dd>{{ $use->purpose }}</dd></div>
            <div><dt>Status</dt><dd>{{ ucfirst($use->status) }}</dd></div>
        </dl>
        @if($use->status === 'conflict')
            <p>Overlapping Accepted resident reservations exist. Manage those bookings in Reservation Management.</p>
        @endif
    </div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
@if(in_array($use->status, ['active', 'conflict'], true))
    <x-modal id="official-use-edit-{{ $use->id }}" title="Edit Official Use #{{ $use->id }}" size="medium">
        <form method="POST" action="{{ route('official-uses.update', $use) }}" data-future-reservation data-original-date="{{ $use->date }}" data-original-start="{{ substr($use->start_time,0,5) }}" data-original-end="{{ substr($use->end_time,0,5) }}" data-original-resource="{{ $use->facility_id }}" data-reservation-action>@csrf @method('PATCH')
            <div class="facility-modal-body">
                <label>Resource<select name="facility_id" required>@foreach($resources as $resource)<option value="{{ $resource->id }}" data-category="{{ $resource->category }}" data-total-quantity="{{ $resource->total_quantity }}" @selected($use->facility_id === $resource->id)>{{ $resource->name }}{{ auth()->user()->role === 'super_admin' ? ' — '.$resource->barangay : '' }}</option>@endforeach</select></label>
                <label data-resource-field="Equipment" @if(!$use->facility->isEquipment()) hidden @endif>Quantity Requested<input name="quantity" type="number" min="1" step="1" value="{{ $use->quantity }}" @if($use->quantity === null && $use->facility->isEquipment()) data-legacy-quantity="{{ $use->facility_id }}" @endif @if(!$use->facility->isEquipment()) disabled @endif></label>
                @if($use->quantity === null && $use->facility->isEquipment())<p>Leave quantity blank to retain this legacy whole-resource hold, or enter a verified quantity after review.</p>@endif
                <label>Date<input name="date" type="date" value="{{ $use->date }}" min="{{ today()->toDateString() }}" required></label>
                <label>Start Time<x-hour-select name="start_time" :value="$use->start_time" legacy /></label>
                <label>End Time<x-hour-select name="end_time" :value="$use->end_time" legacy /></label>
                <x-hourly-summary :next-day="! $use->period()->start->isSameDay($use->period()->end)" />
                <label>Purpose<textarea name="purpose" maxlength="500" rows="3" required>{{ $use->purpose }}</textarea></label>
                <p>Pending requests that cannot be accommodated will be cancelled; accepted residents will be notified of new conflicts. Accepted bookings remain unchanged.</p>
                <p data-action-error role="alert" hidden></p>
            </div>
            <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">Save Changes</button></div>
        </form>
    </x-modal>
@endif
