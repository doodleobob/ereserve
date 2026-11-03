<x-modal id="charge-payment-view-{{ $charge->id }}" title="Equipment Charge Payment History" size="medium">
    <div class="facility-modal-body">
        <dl class="account-info-list">
            <div><dt>Equipment Charge</dt><dd>#{{ $charge->id }}</dd></div>
            <div><dt>Resident</dt><dd>{{ $charge->usage->reservation->user->name }}</dd></div>
            <div><dt>Equipment</dt><dd>{{ $charge->usage->facility->name }}</dd></div>
            <div><dt>Charge Amount</dt><dd>{{ \App\Support\Money::format($charge->amount) }}</dd></div>
            <div><dt>Amount Paid</dt><dd>{{ \App\Support\Money::format((string) $charge->payments->sum('amount')) }}</dd></div>
            <div><dt>Balance</dt><dd>{{ \App\Support\Money::format((string) max(0, (float) $charge->amount - (float) $charge->payments->sum('amount'))) }}</dd></div>
            <div><dt>Status</dt><dd>{{ \App\Models\EquipmentCharge::STATUSES[$charge->status] }}</dd></div>
        </dl>
        <x-management-table label="Equipment charge payment history" compact>
            <thead><tr><th scope="col">Payment Date</th><th scope="col">Amount</th><th scope="col">Recorded By</th><th scope="col">Recorded At</th></tr></thead>
            <tbody>@forelse($charge->payments as $payment)<tr><td>{{ $payment->payment_date->format('M j, Y') }}</td><td>{{ \App\Support\Money::format($payment->amount) }}</td><td>{{ $payment->recorder->name }}</td><td>{{ $payment->created_at->format('M j, Y g:i A') }}</td></tr>@empty<tr><td colspan="4">No payments recorded.</td></tr>@endforelse</tbody>
        </x-management-table>
    </div>
    <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Close</button></div>
</x-modal>
@if($canRecordChargePayments && $charge->status === 'unpaid')
<x-modal id="charge-payment-record-{{ $charge->id }}" title="Record Equipment Charge Payment" size="medium">
    <form method="POST" action="{{ route('equipment-charges.payments.store', $charge) }}">
        @csrf
        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        <div class="facility-modal-body">
            <p>Charge #{{ $charge->id }} for {{ $charge->usage->facility->name }} · {{ $charge->usage->reservation->user->name }}</p>
            <dl class="account-info-list"><div><dt>Total Charge</dt><dd>{{ \App\Support\Money::format($charge->amount) }}</dd></div><div><dt>Outstanding Balance</dt><dd>{{ \App\Support\Money::format((string) $row->balance_amount) }}</dd></div></dl>
            <div class="modal-form-group"><label for="charge-payment-amount-{{ $charge->id }}">Amount Received</label><input id="charge-payment-amount-{{ $charge->id }}" type="number" name="amount" min="0.01" max="{{ number_format((float) $row->balance_amount, 2, '.', '') }}" step="0.01" value="{{ number_format((float) $row->balance_amount, 2, '.', '') }}" required></div>
            <div class="modal-form-group"><label for="charge-payment-date-{{ $charge->id }}">Payment Date</label><input id="charge-payment-date-{{ $charge->id }}" type="date" name="payment_date" value="{{ today()->toDateString() }}" required></div>
            <p class="payment-date-help">Partial payments are allowed. The charge becomes Paid when the full balance has been recorded.</p>
        </div>
        <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-reservation-close>Cancel</button><button type="submit" class="facility-modal-primary">Record Payment</button></div>
    </form>
</x-modal>
@endif
