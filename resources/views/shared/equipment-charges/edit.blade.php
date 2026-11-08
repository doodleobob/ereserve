<x-layouts.user title="Edit Equipment Charge #{{ $charge->id }} - eReserve" active="equipment-charges">
    <section class="page-heading reservations-heading">
        <h2>Edit Equipment Charge #{{ $charge->id }}</h2>
        <p>Update the manually assessed quantity, amount, reason, notes, or status.</p>
    </section>
    @if($errors->any())<div class="reservation-alert reservation-alert-error" role="alert">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('equipment-charges.update', $charge) }}" class="filter-card charge-form">
        @csrf
        @method('PATCH')
        @if(auth()->user()->role === 'super_admin')<p class="charge-form-context charge-full-field">Managing Barangay: {{ $charge->usage->facility->barangay }}</p>@endif
        <p class="charge-form-context charge-full-field"><strong>{{ $charge->usage->facility->name }}</strong> for {{ $charge->usage->reservation->user->name }} · Usage #{{ $charge->resource_usage_id }} · Outstanding {{ $charge->usage->outstanding() }}</p>
        <div class="filter-group">
            <label for="charge-quantity">Affected Quantity</label>
            <input id="charge-quantity" type="number" name="quantity" min="1" value="{{ old('quantity', $charge->quantity) }}" required>
        </div>
        <div class="filter-group">
            <label for="charge-amount">Charge Amount (₱)</label>
            <input id="charge-amount" type="number" name="amount" min="0.01" max="99999999.99" step="0.01" value="{{ old('amount', $charge->amount) }}" required>
        </div>
        <div class="filter-group charge-full-field">
            <label for="charge-reason">Reason</label>
            @php($savedReason = str_starts_with($charge->reason, 'Other: ') ? 'Other' : $charge->reason)
            @php($savedOtherReason = str_starts_with($charge->reason, 'Other: ') ? substr($charge->reason, 7) : '')
            <select id="charge-reason" name="reason" required data-charge-reason>
                <option value="">Select a reason</option>
                @foreach(\App\Services\EquipmentChargeManagement::REASONS as $reason)<option value="{{ $reason }}" @selected(old('reason', $savedReason) === $reason)>{{ $reason }}</option>@endforeach
            </select>
            <div class="filter-group charge-full-field" data-charge-reason-other @if(old('reason', $savedReason) !== 'Other') hidden @endif>
                <label for="charge-reason-other-input">Specify Other Reason</label>
                <input id="charge-reason-other-input" type="text" maxlength="1900" name="reason_other" value="{{ old('reason_other', $savedOtherReason) }}" @if(old('reason', $savedReason) === 'Other') required @endif>
            </div>
        </div>
        <div class="filter-group">
            <label for="charge-status">Status</label>
            <select id="charge-status" name="status" required>
                <option value="unpaid" @selected(old('status', $charge->status)==='unpaid')>Unpaid</option>
                @if($charge->status === 'paid')<option value="paid" @selected(old('status', $charge->status)==='paid')>Paid</option>@endif
                <option value="cancelled" @selected(old('status', $charge->status)==='cancelled')>Cancelled</option>
                <option value="waived" @selected(old('status', $charge->status)==='waived')>Waived</option>
            </select>
        </div>
        <div class="filter-group charge-full-field">
            <label for="charge-notes">Additional Notes (optional)</label>
            <textarea id="charge-notes" name="notes" maxlength="5000">{{ old('notes', $charge->notes) }}</textarea>
        </div>
        <p class="payment-date-help charge-full-field">Paid status is unavailable because this module has no verified charge payment recording process.</p>
        <div class="filter-actions charge-full-field">
            <a class="button button-secondary" href="{{ route('equipment-charges.show', $charge) }}">Cancel</a>
            <button class="button button-primary" type="submit">Save Changes</button>
        </div>
    </form>
    @push('scripts')
        <script src="{{ asset('js/equipment-charges.js') }}?v={{ filemtime(public_path('js/equipment-charges.js')) }}" defer></script>
    @endpush
</x-layouts.user>
