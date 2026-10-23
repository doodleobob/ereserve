<section class="equipment-inventory" aria-label="Equipment inventory">
    <p class="inventory-introduction">Physical stock reflects confirmed releases and returns. Accepted schedules and their 60-minute buffers are checked separately.</p>
    @if($errors->any())<p class="form-error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="GET" action="{{ route('equipment') }}" class="filter-card filter-toolbar">
        <input type="hidden" name="tab" value="inventory">
        <label>Search equipment<input type="search" name="search" value="{{ request('search') }}" maxlength="200"></label>
        @if(auth()->user()->role === 'super_admin')
            <label>Barangay<select name="barangay"><option value="">All barangays</option>@foreach($barangays as $barangay)<option @selected(request('barangay') === $barangay)>{{ $barangay }}</option>@endforeach</select></label>
        @endif
        <label>Show entries<select name="per_page">@foreach([10,25,50,100] as $count)<option value="{{ $count }}" @selected((int) request('per_page',10) === $count)>{{ $count }}</option>@endforeach</select></label>
        <div class="filter-actions"><button type="submit" class="facility-modal-primary">Apply</button>
        <a href="{{ route('equipment', ['tab'=>'inventory']) }}" class="facility-modal-secondary">Reset</a></div>
    </form>
    <x-management-table label="Equipment inventory">
        <thead><tr><th>Equipment</th><th>Barangay</th><th>Recorded stock</th><th>Usable</th><th>Physically available</th><th>Borrowed</th><th>Damaged / unavailable</th><th>Overdue</th><th>Status</th><th>Last inventory update</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse($inventoryItems as $equipment)
            @php($stock = $inventorySummaries[$equipment->id])
            <tr>
                <td>{{ $equipment->name }}</td><td>{{ $equipment->barangay }}</td>
                @foreach(['total','usable','available','outstanding','damaged','overdue'] as $key)<td>{{ $stock[$key] ?? 'Unverified' }}</td>@endforeach
                <td><span class="reservation-status reservation-status-{{ $stock['inconsistent'] || $stock['overdue'] ? 'rejected' : ($equipment->status !== 'Available' || $stock['usable'] === null ? 'pending' : 'accepted') }}">{{ $stock['inconsistent'] ? 'Needs reconciliation' : ($stock['overdue'] ? 'Overdue loans' : ($stock['usable'] === null ? 'Unverified' : $equipment->status)) }}</span></td>
                <td>{{ $equipment->inventory_adjustments_max_created_at ? \Illuminate\Support\Carbon::parse($equipment->inventory_adjustments_max_created_at)->format('M j, Y g:i A') : 'No adjustment recorded' }}</td>
                <td><x-table-actions id="inventory-actions-{{ $equipment->id }}" label="Actions for {{ $equipment->name }}">
                    <button type="button" data-modal-open="inventory-details-{{ $equipment->id }}">View Details</button>
                    @can('adjust', [\App\Models\EquipmentInventoryAdjustment::class, $equipment])<button type="button" data-modal-open="inventory-adjust-{{ $equipment->id }}">Adjust Stock</button>@endcan
                    <a href="{{ route('equipment', [...request()->except('history_page'), 'tab'=>'inventory', 'history'=>$equipment->id]) }}#inventory-history">Inventory History</a>
                </x-table-actions></td>
            </tr>
        @empty<tr><td colspan="11">No equipment matches these filters.</td></tr>@endforelse
        </tbody>
    </x-management-table>
    <x-table-pagination :paginator="$inventoryItems" label="Equipment inventory pagination" />
    @foreach($inventoryItems as $equipment)
        @php($stock = $inventorySummaries[$equipment->id])
        <x-modal id="inventory-details-{{ $equipment->id }}" title="Inventory Details" size="small" class="facility-view-modal inventory-details">
            <div class="facility-modal-body facility-view-body">
                <section class="inventory-details-info" aria-label="Equipment information">
                    <h4>{{ $equipment->name }}</h4>
                    <p>{{ $equipment->barangay }}</p>
                </section>
                <dl class="equipment-card-facts">
                    @foreach(['total'=>'Total Recorded Stock','usable'=>'Usable Stock','available'=>'Physically Available','outstanding'=>'Currently Borrowed','damaged'=>'Damaged / Unavailable Units','overdue'=>'Overdue Quantity'] as $key=>$label)<div><dt>{{ $label }}</dt><dd>{{ $stock[$key] ?? 'Unverified' }}</dd></div>@endforeach
                </dl>
                <section class="inventory-details-info" aria-label="Stock explanation">
                    <p>Usable stock is recorded stock minus damaged units. Physically available stock is usable stock minus outstanding loans. Marking the resource unavailable blocks reservations and releases without changing these counts.</p>
                    <p><a href="{{ route('resource-usage.index', ['resource_id'=>$equipment->id, 'outstanding'=>1]) }}">View currently borrowed equipment</a></p>
                    <p><a href="{{ route('resource-usage.index', ['resource_id'=>$equipment->id, 'overdue'=>1]) }}">View overdue equipment</a></p>
                </section>
            </div>
            <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Close</button></div>
        </x-modal>
        @can('adjust', [\App\Models\EquipmentInventoryAdjustment::class, $equipment])
            <x-modal id="inventory-adjust-{{ $equipment->id }}" title="Adjust Stock" size="medium" class="facility-reservation-modal inventory-details">
                <form method="POST" action="{{ route('equipment.inventory.store', $equipment) }}" class="reservation-form" data-modal-action data-inventory-preview="{{ route('equipment.inventory.preview', $equipment) }}">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="facility-modal-body reservation-form-fields">
                        <section class="inventory-details-info" aria-label="Equipment information">
                            <h4>{{ $equipment->name }}</h4><p>{{ $equipment->barangay }}</p>
                        </section>
                        <dl class="equipment-card-facts">
                            @foreach(['total'=>'Recorded stock','usable'=>'Usable stock','outstanding'=>'Borrowed quantity','damaged'=>'Damaged / unavailable'] as $key=>$label)
                                <div><dt>{{ $label }}</dt><dd>{{ $stock[$key] ?? 'Unverified' }}</dd></div>
                            @endforeach
                        </dl>
                        <p data-action-error role="alert" hidden></p>
                        <div class="modal-form-group">
                            <label for="adjust-type-{{ $equipment->id }}">Adjustment type (required)</label>
                            <select id="adjust-type-{{ $equipment->id }}" name="adjustment_type" required aria-describedby="adjust-guidance-{{ $equipment->id }}">
                                <option value="add" data-guidance="Adds new units to recorded and usable inventory.">Add stock</option>
                                <option value="reduce" data-guidance="Removes physically present usable units from recorded inventory. Damaged stock stays unchanged; borrowed units and scheduled allocations must remain covered.">Reduce usable stock</option>
                                <option value="damage" data-guidance="Marks physically present usable units as damaged / unavailable. Recorded stock stays unchanged; borrowed units and scheduled allocations must remain covered.">Mark damaged / unavailable</option>
                                <option value="repair" data-guidance="Returns damaged units to usable stock after repair. Recorded stock stays unchanged.">Restore repaired units</option>
                                <option value="dispose" data-guidance="Permanently removes damaged units from recorded inventory. Usable stock stays unchanged.">Remove damaged units</option>
                            </select>
                            <p id="adjust-guidance-{{ $equipment->id }}" class="inventory-details-info" data-adjustment-guidance aria-live="polite">Adds new units to recorded and usable inventory.</p>
                        </div>
                        <div class="modal-form-group"><label for="adjust-quantity-{{ $equipment->id }}">Quantity (required)</label><input id="adjust-quantity-{{ $equipment->id }}" name="quantity" type="number" min="1" max="4294967295" step="1" required></div>
                        <section class="modal-form-group" aria-label="Stock preview">
                            <p data-stock-preview-status role="status">Enter a quantity to preview the adjustment. Stock is rechecked when you save.</p>
                            <x-management-table label="Stock before and after adjustment" compact class="inventory-adjust-preview" data-stock-preview hidden>
                                <thead><tr><th scope="col">Stock</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
                                <tbody>@foreach(['total'=>'Recorded','usable'=>'Usable','damaged'=>'Damaged'] as $key=>$label)<tr data-stock-row="{{ $key }}"><th scope="row">{{ $label }}</th><td data-before></td><td data-after></td></tr>@endforeach</tbody>
                            </x-management-table>
                        </section>
                        <div class="modal-form-group"><label for="adjust-reason-{{ $equipment->id }}">Reason / remarks (required)</label><textarea id="adjust-reason-{{ $equipment->id }}" name="reason" maxlength="1000" rows="3" required></textarea></div>
                        <div class="modal-form-group"><label class="inventory-confirmation"><input type="checkbox" name="confirmed" value="1" required> I confirm that this inventory adjustment is correct and will be recorded in inventory history.</label></div>
                    </div>
                    <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary">Record Adjustment</button></div>
                </form>
            </x-modal>
        @endcan
    @endforeach
    @if($historyEquipment)
        <section id="inventory-history" aria-label="Inventory adjustment history">
            <h3>Inventory History — {{ $historyEquipment->name }}</h3><p>{{ $historyEquipment->barangay }} · Recorded stock before tracking remains unchanged. Earlier adjustments cannot be reconstructed.</p>
            <x-management-table label="Inventory adjustment history">
                <thead><tr><th>Time</th><th>Type</th><th>Quantity</th><th>Recorded before</th><th>Recorded after</th><th>Damaged before</th><th>Damaged after</th><th>Reason</th><th>Responsible admin</th></tr></thead>
                <tbody>@forelse($inventoryHistory as $entry)<tr><td>{{ $entry->created_at->format('M j, Y g:i A') }}</td><td>{{ ucfirst($entry->adjustment_type) }}</td><td>{{ $entry->quantity }}</td><td>{{ $entry->total_before ?? 'Unverified' }}</td><td>{{ $entry->total_after }}</td><td>{{ $entry->damaged_before }}</td><td>{{ $entry->damaged_after }}</td><td class="inventory-reason">{{ $entry->reason }}</td><td>{{ $entry->actor_name }}</td></tr>@empty<tr><td colspan="9">No inventory adjustments recorded.</td></tr>@endforelse</tbody>
            </x-management-table>
            <x-table-pagination :paginator="$inventoryHistory" label="Inventory history pagination" />
        </section>
    @endif
</section>
