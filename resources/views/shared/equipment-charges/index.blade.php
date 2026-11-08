<x-layouts.user title="Equipment Charges - eReserve" active="equipment-charges">
    <section class="page-heading page-header reservations-heading">
        <div><h2>Equipment Charges</h2>
        <p>{{ $canManage ? 'Record and review manual charges for equipment that remains outstanding.' : 'Review charges recorded for your equipment borrowing.' }}</p></div>
        @if($canManage)<button type="button" class="button button-primary button-create" data-modal-open="record-equipment-charge" aria-label="+ Record Charge"><x-add-icon />Record Charge</button>@endif
    </section>

    @if(session('charge_status'))<div class="reservation-alert" role="status">{{ session('charge_status') }}</div>@endif

    @if($canManage)
        <x-modal id="record-equipment-charge" title="Record Equipment Charge" size="medium" class="facility-reservation-modal">
            <form method="POST" action="{{ route('equipment-charges.store') }}" class="reservation-form" data-equipment-charge-form>
                @csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="facility-modal-body reservation-form-fields charge-modal-fields">
                    @if(old('request_key'))<span hidden data-charge-auto-open></span>@endif
                    @if($errors->any())<p class="reservation-alert reservation-alert-error" role="alert">{{ $errors->first() }}</p>@endif
                    <div class="modal-form-group charge-modal-wide">
                        <label for="charge-usage">Borrowing Record</label>
                        <select id="charge-usage" name="resource_usage_id" required>
                            <option value="">Select equipment borrowing</option>
                            @foreach($usages as $usage)
                                <option value="{{ $usage->id }}" @selected(old('resource_usage_id') == $usage->id)>Usage #{{ $usage->id }} · {{ $usage->facility->name }} · {{ $usage->reservation->user->name }} · Outstanding {{ $usage->outstanding() }}</option>
                            @endforeach
                        </select>
                        @error('resource_usage_id')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="modal-form-group">
                        <label for="charge-quantity">Affected Quantity</label>
                        <input id="charge-quantity" type="number" min="1" name="quantity" value="{{ old('quantity') }}" required>
                        @error('quantity')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="modal-form-group">
                        <label for="charge-amount">Charge Amount (₱)</label>
                        <input id="charge-amount" type="number" min="0.01" step="0.01" name="amount" value="{{ old('amount') }}" required>
                        @error('amount')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="modal-form-group charge-modal-wide">
                        <label for="charge-reason">Reason</label>
                        <select id="charge-reason" name="reason" required data-charge-reason>
                            <option value="">Select a reason</option>
                            @foreach(\App\Services\EquipmentChargeManagement::REASONS as $reason)<option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ $reason }}</option>@endforeach
                        </select>
                        @error('reason')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                        <div class="modal-form-group charge-modal-wide" data-charge-reason-other @if(old('reason') !== 'Other') hidden @endif>
                            <label for="charge-reason-other-input">Specify Other Reason</label>
                            <input id="charge-reason-other-input" type="text" maxlength="1900" name="reason_other" value="{{ old('reason_other') }}" @if(old('reason') === 'Other') required @endif>
                            @error('reason_other')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="modal-form-group charge-modal-wide">
                        <label for="charge-notes">Additional Notes (optional)</label>
                        <textarea id="charge-notes" name="notes" maxlength="5000" rows="3">{{ old('notes') }}</textarea>
                        @error('notes')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <p class="payment-date-help charge-modal-wide">The admin sets the amount manually. Creating a charge does not record payment.</p>
                </div>
                <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary">Record Charge</button></div>
            </form>
        </x-modal>
    @endif

    <div data-equipment-charge-table data-page="{{ $charges->currentPage() }}">
        <form method="GET" action="{{ route('equipment-charges.index') }}" class="filter-card filter-toolbar payment-filters" id="charge-filters" aria-label="Equipment charge search">
            <div class="filter-group">
                <label for="charge-search">Search</label>
                <input id="charge-search" type="search" name="search" maxlength="200" value="{{ request('search') }}" placeholder="Equipment, resident, usage, reason">
            </div>
            <div class="filter-actions">
                <button type="submit" class="reservation-table-search">Search</button>
                <a class="button button-secondary" data-table-link href="{{ route('equipment-charges.index') }}">Reset</a>
            </div>
        </form>
        <div class="reservation-table-toolbar">
            <x-table-entries :paginator="$charges" form="charge-filters" id="charge-rows" noun="charges" />
        </div>
        <div class="reservation-datatable-scroll" tabindex="0" aria-label="Equipment charges table; scroll horizontally on small screens">
            <table class="reservation-datatable" aria-label="Equipment Charges">
                <thead><tr><th scope="col">Charge ID</th><th scope="col">Resident</th><th scope="col">Equipment</th>@if($systemWide)<th scope="col">Managing Barangay</th>@endif<th scope="col">Usage Reference</th><th scope="col">Quantity</th><th scope="col">Amount</th><th scope="col">Reason</th><th scope="col">Status</th><th scope="col">Date Created</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @forelse($charges as $charge)
                        <tr>
                            <td>#{{ $charge->id }}</td>
                            <td>{{ $charge->usage->reservation->user->name }}</td>
                            <td>{{ $charge->usage->facility->name }}</td>
                            @if($systemWide)<td>{{ $charge->usage->facility->barangay }}</td>@endif
                            <td>#{{ $charge->resource_usage_id }} / Reservation #{{ $charge->usage->reservation_id }}</td>
                            <td class="charge-number">{{ $charge->quantity }}</td>
                            <td class="charge-number">₱{{ number_format((float) $charge->amount, 2) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($charge->reason, 60) }}</td>
                            <td><span class="reservation-status charge-status-{{ $charge->status }}">{{ \App\Models\EquipmentCharge::STATUSES[$charge->status] }}</span></td>
                            <td>{{ $charge->created_at->format('M j, Y') }}</td>
                            <td><a class="button button-secondary button-compact" href="{{ route('equipment-charges.show', $charge) }}">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $systemWide ? 11 : 10 }}">No equipment charges recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-table-pagination :paginator="$charges" label="Equipment charges table pagination" />
    </div>
    @push('scripts')
        <script src="{{ asset('js/reservation-datatable.js') }}?v={{ filemtime(public_path('js/reservation-datatable.js')) }}" defer></script>
        <script src="{{ asset('js/equipment-charges.js') }}?v={{ filemtime(public_path('js/equipment-charges.js')) }}" defer></script>
    @endpush
</x-layouts.user>
