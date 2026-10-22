@php
    $row = \App\Support\ResourceUsageReport::row($reservation, $systemWide);
    $usage = $reservation->resourceUsage;
    $resource = $usage?->facility ?? $reservation->facility;
    $equipment = $reservation->usage_type === 'Equipment';
    $inventory = $equipment && $resource ? \App\Services\ResourceUsageManagement::inventory($resource) : null;
    $scheduledAvailable = $equipment && $resource && $resource->total_quantity !== null ? \App\Support\ReservationAvailability::availableQuantity($resource, $reservation->period()) : null;
@endphp
<x-modal id="usage-details-{{ $reservation->id }}" title="Resource Usage Details" size="medium">
    <div class="facility-modal-body">
        <dl class="account-info-list">@foreach($row as $key=>$value)<div><dt>{{ $columns[$key] }}</dt><dd>{{ $value }}</dd></div>@endforeach<div><dt>Reservation Status</dt><dd>{{ ucfirst($reservation->status) }}</dd></div></dl>
        @include('shared.resource-usage.inventory')
    </div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
<x-modal id="usage-history-{{ $reservation->id }}" title="Usage History — Reservation #{{ $reservation->id }}" size="medium">
    <div class="facility-modal-body">
        @forelse($usage?->events ?? [] as $event)
            <section><h4>{{ ['check_in'=>'Check In','check_out'=>'Check Out','release'=>'Equipment Released','return'=>'Equipment Returned'][$event->event_type] }}</h4>
                <dl class="account-info-list"><div><dt>Transaction</dt><dd>#{{ $event->id }}</dd></div><div><dt>Confirmed By</dt><dd>{{ $event->actor_name }} (#{{ $event->performed_by }})</dd></div><div><dt>Actual Time</dt><dd>{{ $event->occurred_at->format('Y-m-d H:i:s') }}</dd></div>@if($event->quantity)<div><dt>Quantity</dt><dd>{{ $event->quantity }}</dd></div>@endif<div><dt>Schedule at Confirmation</dt><dd>{{ $event->scheduled_start->format('Y-m-d H:i') }} – {{ $event->scheduled_end->format('Y-m-d H:i') }}</dd></div>@if($event->notes)<div><dt>Notes</dt><dd>{{ $event->notes }}</dd></div>@endif</dl>
            </section>
        @empty<p>No actual usage has been confirmed.</p>@endforelse
    </div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
@can('operate', [\App\Models\ResourceUsage::class, $reservation])
    @foreach(\App\Support\ResourceUsagePresentation::actions($reservation) as $action=>$label)
    <x-modal id="usage-{{ $action }}-{{ $reservation->id }}" :title="$label" size="medium">
        <form method="POST" action="{{ route('resource-usage.store', $reservation) }}" data-reservation-action>@csrf
            <input type="hidden" name="action" value="{{ $action }}"><input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <div class="facility-modal-body">
                <dl class="account-info-list">
                    @foreach(['resource','resident','reservation'] as $key)<div><dt>{{ $columns[$key] }}</dt><dd>{{ $row[$key] }}</dd></div>@endforeach
                    @if($equipment)@foreach(['approved','released','returned','outstanding'] as $key)<div><dt>{{ $columns[$key] }}</dt><dd>{{ $row[$key] }}</dd></div>@endforeach @endif
                </dl>
                @if($action === 'release')@include('shared.resource-usage.inventory')@endif
                @if($equipment)<label class="reservation-confirmed-total">{{ $action === 'release' ? 'Quantity to Release' : 'Additional Return Quantity' }}<input type="number" name="quantity" min="1" max="{{ $action === 'release' ? min(max(0, $row['approved'] - $row['released']), $inventory['available'] ?? 0) : $row['outstanding'] }}" step="1" required></label>@endif
                <label class="reservation-confirmed-total">Notes (optional)<textarea name="notes" maxlength="1000" rows="3"></textarea></label>
                <label class="payment-receipt"><input type="checkbox" name="confirmed" value="1" required> I confirm this {{ $equipment ? ($action === 'release' ? 'equipment handover' : 'equipment return') : ($action === 'check_in' ? 'facility check-in' : 'facility check-out') }} has actually occurred.</label>
                <p data-action-error role="alert" hidden></p>
            </div>
            <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">{{ $action === 'return' ? 'Confirm Return' : 'Confirm '.$label }}</button></div>
        </form>
    </x-modal>
    @endforeach
@endcan
