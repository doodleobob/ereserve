<x-layouts.user title="Equipment Charge #{{ $charge->id }} - eReserve" active="equipment-charges">
    <section class="page-heading reservations-heading">
        <h2>Equipment Charge #{{ $charge->id }}</h2>
        <p>Charge details and borrowing reference.</p>
    </section>
    @if(session('charge_status'))<div class="reservation-alert" role="status">{{ session('charge_status') }}</div>@endif
    <section class="filter-card charge-details-card">
        <dl class="charge-details">
            <div><dt>Resident</dt><dd>{{ $charge->usage->reservation->user->name }}</dd></div>
            <div><dt>Resident email</dt><dd>{{ auth()->user()->role === 'admin' ? $charge->usage->reservation->user->email : 'Hidden' }}</dd></div>
            <div><dt>Equipment</dt><dd>{{ $charge->usage->facility->name }}</dd></div>
            <div><dt>Resource Usage</dt><dd>#{{ $charge->resource_usage_id }}</dd></div>
            <div><dt>Reservation</dt><dd>#{{ $charge->usage->reservation_id }}</dd></div>
            <div><dt>Approved / Released / Returned</dt><dd>{{ $charge->usage->approved_quantity }} / {{ $charge->usage->released_quantity }} / {{ $charge->usage->returned_quantity }}</dd></div>
            <div><dt>Quantity charged</dt><dd>{{ $charge->quantity }}</dd></div>
            <div><dt>Amount</dt><dd>₱{{ number_format((float) $charge->amount, 2) }}</dd></div>
            <div class="charge-details-wide"><dt>Reason</dt><dd>{{ $charge->reason }}</dd></div>
            <div class="charge-details-wide"><dt>Notes</dt><dd>{{ $charge->notes ?: '—' }}</dd></div>
            <div><dt>Status</dt><dd><span class="reservation-status charge-status-{{ $charge->status }}">{{ \App\Models\EquipmentCharge::STATUSES[$charge->status] }}</span></dd></div>
            <div><dt>Recorded by</dt><dd>{{ $charge->creator->name }}</dd></div>
            <div><dt>Created</dt><dd>{{ $charge->created_at->format('M j, Y g:i A') }}</dd></div>
            <div><dt>Updated</dt><dd>{{ $charge->updated_at->format('M j, Y g:i A') }}</dd></div>
        </dl>
        @php($paidAmount = $charge->payments->sum('amount'))
        <section class="charge-history" aria-labelledby="charge-payments-heading">
            <h3 id="charge-payments-heading">Payment History</h3>
            <p>Paid: ₱{{ number_format((float) $paidAmount, 2) }} · Balance: ₱{{ number_format(max(0, (float) $charge->amount - (float) $paidAmount), 2) }}</p>
            <x-management-table label="Equipment charge payments" compact>
                <thead><tr><th scope="col">Payment Date</th><th scope="col">Amount</th><th scope="col">Recorded By</th><th scope="col">Recorded At</th></tr></thead>
                <tbody>@forelse($charge->payments as $payment)<tr><td>{{ $payment->payment_date->format('M j, Y') }}</td><td>₱{{ number_format((float) $payment->amount, 2) }}</td><td>{{ $payment->recorder->name }}</td><td>{{ $payment->created_at->format('M j, Y g:i A') }}</td></tr>@empty<tr><td colspan="4">No payments recorded.</td></tr>@endforelse</tbody>
            </x-management-table>
        </section>
        @can('manage', $charge)
            <section class="charge-history" aria-labelledby="charge-history-heading">
                <h3 id="charge-history-heading">Change History</h3>
                @forelse($charge->changes as $change)
                    <p>{{ $change->created_at->format('M j, Y g:i A') }} — {{ $change->actor->name }} updated charge details.</p>
                @empty
                    <p>No edits recorded.</p>
                @endforelse
            </section>
        @endcan
        <div class="filter-actions charge-details-actions">
            <a class="button button-secondary" href="{{ route('equipment-charges.index') }}">Back to Charges</a>
            @can('manage', $charge)<a class="button button-primary" href="{{ route('equipment-charges.edit', $charge) }}">Edit Charge</a>@endcan
        </div>
    </section>
</x-layouts.user>
