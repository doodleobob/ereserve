<x-layouts.user title="Payments - eReserve" active="payments">
    @php($systemWide = auth()->user()->role === 'super_admin')
    @php($residentView = auth()->user()->role === 'user')
    <section class="page-heading reservations-heading"><h2>Payments</h2><p>{{ $residentView ? 'Review reservation and equipment charge payment records.' : ($systemWide ? 'View payment records across all barangays (read-only).' : 'View and manage reservation and equipment charge payment records.') }}</p></section>
    @if(session('charge_payment_status'))<div class="reservation-alert" role="status">{{ session('charge_payment_status') }}</div>@endif
    <div data-payment-table data-page="{{ $payments->currentPage() }}">
        <form method="GET" action="{{ route('payments.index') }}" class="filter-card filter-toolbar payment-filters" id="payment-filters" aria-label="Payment filters">
            @foreach(['sort','direction'] as $key)@if(request()->filled($key))<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif @endforeach
            <div class="filter-group"><label for="search">Search</label><input id="search" type="search" name="search" maxlength="200" value="{{ request('search') }}" placeholder="Payment, charge, resident, or resource"></div>
            <div class="filter-group"><label for="payment-type">Payment Type</label><select id="payment-type" name="type" data-auto-submit><option value="all" @selected(request('type', 'all') === 'all')>All Payments</option><option value="reservation" @selected(request('type') === 'reservation')>Reservation</option><option value="equipment_charge" @selected(request('type') === 'equipment_charge')>Equipment Charge</option></select></div>
            @if($systemWide)
                <div class="filter-group"><label for="payment-barangay">Barangay</label><select id="payment-barangay" name="barangay" data-auto-submit><option value="">All Barangays</option>@foreach(\App\Support\Barangays::ALL as $barangay)<option value="{{ $barangay }}" @selected(request('barangay') === $barangay)>{{ $barangay }}</option>@endforeach</select></div>
            @endif
            <div class="filter-group"><label for="status">Payment Status</label><select id="status" name="status" data-auto-submit><option value="all">All Statuses</option>@foreach([...\App\Models\Payment::STATUSES, 'unpaid', 'waived'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ \App\Models\Payment::statusLabel($status) }}</option>@endforeach</select></div>
            <div class="filter-group"><label for="from-date">From Date</label><input id="from-date" type="date" name="from_date" value="{{ request('from_date') }}" data-auto-submit></div>
            <div class="filter-group"><label for="to-date">To Date</label><input id="to-date" type="date" name="to_date" value="{{ request('to_date') }}" data-auto-submit></div>
            <div class="filter-actions"><button type="submit" class="reservation-table-search">{{ $systemWide ? 'Apply' : 'Search' }}</button>@if($systemWide)<a class="button button-secondary" data-table-link href="{{ route('payments.index') }}">Reset</a>@endif</div>
            <p class="payment-date-help">Date filters use the payment recorded date. Reservation dates are shown separately.</p>
        </form>
        <div class="reservation-table-toolbar">
            <div class="payment-summary" role="status"><span>Total Records: <strong data-payment-count>{{ number_format($summary['count']) }}</strong></span><span>Total Amount: <strong data-payment-total>{{ \App\Support\Money::format($summary['total']) }}</strong></span>@if($summary['unrecorded'])<small>{{ $summary['unrecorded'] }} reservation total(s) not recorded; excluded from Total.</small>@endif</div>
            @if(! $residentView)<details class="payment-download"><summary>Download <span aria-hidden="true">&#9662;</span></summary><div>@foreach(['pdf'=>'Download PDF','xlsx'=>'Download Excel'] as $format=>$label)<a data-payment-download href="{{ route('payments.export-centralized', array_merge(request()->only('search','type','barangay','status','from_date','to_date','sort','direction'), ['format'=>$format])) }}">{{ $label }}</a>@endforeach</div></details>@endif
        </div>
        <div class="reservation-table-toolbar"><label for="payment-rows">Show <select id="payment-rows" name="per_page" form="payment-filters" data-table-filter>@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($payments->perPage()===$size)>{{ $size }}</option>@endforeach</select> entries</label><p>Showing {{ $payments->firstItem() ?? 0 }} to {{ $payments->lastItem() ?? 0 }} of {{ $payments->total() }} payments</p></div>
        <div class="reservation-datatable-scroll" tabindex="0" aria-label="Payments table; scroll horizontally on small screens"><table class="reservation-datatable" aria-label="Payments"><thead><tr>
            @foreach(['id'=>'Payment ID','type'=>'Payment Type','reservation'=>'Reservation / Charge','barangay'=>'Barangay','resident'=>'Resident','resource'=>'Resource','date'=>'Payment Date','total'=>'Total Amount','status'=>'Payment Status'] as $sort=>$label)
                @if($sort !== 'barangay' || $systemWide)
                    @php($active=(request('sort') ?: 'id')===$sort)
                    @php($ascending=(request('direction') ?: 'desc')==='asc')
                    <th scope="col" aria-sort="{{ $active ? ($ascending ? 'ascending' : 'descending') : 'none' }}"><a data-table-link href="{{ route('payments.index', array_merge(request()->except('page'), ['sort'=>$sort,'direction'=>$active && $ascending ? 'desc' : 'asc'])) }}">{{ $label }} <span aria-hidden="true">@if($active)@if($ascending)&#8593;@else&#8595;@endif @else&#8597;@endif</span></a></th>
                @endif
            @endforeach<th scope="col">Actions</th>
        </tr></thead><tbody>
            @forelse($payments as $row)
                <tr data-payment-type="{{ $row->payment_type }}" data-payment-id="{{ $row->id }}">
                    <td>{{ $row->payment_type === 'equipment_charge' ? 'CHG-' : 'PAY-' }}#{{ $row->id }}</td>
                    <td>{{ $row->payment_type === 'equipment_charge' ? 'Equipment Charge' : 'Reservation' }}</td>
                    <td>{{ $row->payment_type === 'equipment_charge' ? 'Charge #'.$row->charge_id.' · Usage #'.$row->charge->resource_usage_id : 'Reservation #'.$row->reservation_id }}</td>
                    @if($systemWide)<td>{{ $row->barangay }}</td>@endif
                    <td>{{ $row->resident_name ?: 'Former resident' }}</td>
                    <td>{{ $row->resource_name }}</td>
                    <td>{{ $row->payment_date?->format('M j, Y') ?: 'Not recorded' }}<small>Reservation: {{ \Illuminate\Support\Carbon::parse($row->scheduled_date)->format('M j, Y') }}</small></td>
                    <td>{{ \App\Support\Money::format($row->total_amount) }}@if($row->payment_type === 'equipment_charge')<small>Paid {{ \App\Support\Money::format((string) $row->paid_amount) }} · Balance {{ \App\Support\Money::format((string) $row->balance_amount) }}</small>@endif</td>
                    <td><span class="reservation-status payment-status-{{ $row->status }}">{{ \App\Models\Payment::statusLabel($row->status) }}</span></td>
                    <td>
                        @if($row->payment_type === 'reservation')
                            <x-table-actions id="payment-actions-{{ $row->payment_id }}" label="Actions for Payment {{ $row->payment_id }}"><button type="button" data-reservation-open="payment-view-{{ $row->payment_id }}">View</button>@if($canConfirmRefunds && $row->status === 'refund_pending')<button type="button" class="button-primary" data-reservation-open="payment-refund-{{ $row->payment_id }}">Confirm Refund</button>@endif</x-table-actions>
                        @else
                            <x-table-actions id="charge-payment-actions-{{ $row->charge_id }}" label="Actions for Equipment Charge {{ $row->charge_id }}"><button type="button" data-reservation-open="charge-payment-view-{{ $row->charge_id }}">View History</button>@if($canRecordChargePayments && $row->status === 'unpaid')<button type="button" class="button-primary" data-reservation-open="charge-payment-record-{{ $row->charge_id }}">Record Payment</button>@endif</x-table-actions>
                        @endif
                    </td>
                </tr>
            @empty<tr><td colspan="{{ $systemWide ? 10 : 9 }}">No payment records found.</td></tr>@endforelse
        </tbody></table></div>
        <x-table-pagination :paginator="$payments" label="Payments table pagination" />
        @foreach($payments as $row)
            @if($row->payment_type === 'reservation')
                @include('shared.payments.partials.dialogs', ['payment' => $row->payment])
            @else
                @include('shared.payments.partials.charge-dialogs', ['charge' => $row->charge, 'canRecordChargePayments' => $canRecordChargePayments])
            @endif
        @endforeach
        <noscript><p class="reservation-alert">Enable JavaScript to view payment details and record equipment charge payments.</p></noscript>
    </div>
    @push('scripts')
        <script src="{{ asset('js/reservation-datatable.js') }}?v={{ filemtime(public_path('js/reservation-datatable.js')) }}" defer></script>
    @endpush
</x-layouts.user>
