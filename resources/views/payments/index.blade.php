<x-layouts.user title="Payments - eReserve" active="payments">
    <section class="page-heading reservations-heading"><h2>Payments</h2><p>View and manage reservation payment records.</p></section>
    <div data-payment-table data-page="{{ $payments->currentPage() }}">
        <form method="GET" action="{{ route('payments.index') }}" class="filter-card payment-filters" id="payment-filters" aria-label="Payment filters">
            @foreach(['sort','direction'] as $key)@if(request()->filled($key))<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif @endforeach
            <div class="filter-group"><label for="search">Search</label><input id="search" type="search" name="search" maxlength="200" value="{{ request('search') }}" placeholder="Payment ID, reservation ID, resident, resource"></div>
            <div class="filter-group"><label for="status">Payment Status</label><select id="status" name="status" data-auto-submit><option value="all">All Statuses</option>@foreach(\App\Models\Payment::STATUSES as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
            <div class="filter-group"><label for="from-date">From Date</label><input id="from-date" type="date" name="from_date" value="{{ request('from_date') }}" data-auto-submit></div>
            <div class="filter-group"><label for="to-date">To Date</label><input id="to-date" type="date" name="to_date" value="{{ request('to_date') }}" data-auto-submit></div>
            <button type="submit" class="reservation-table-search">Search</button>
            <p class="payment-date-help">Date filters use the payment recorded date. The table shows the reservation's scheduled date.</p>
        </form>
        <div class="reservation-table-toolbar">
            <div class="payment-summary" role="status"><span>Total Payments: <strong data-payment-count>{{ number_format($summary['count']) }}</strong></span><span>Total: <strong data-payment-total>{{ \App\Support\Money::format($summary['total']) }}</strong></span>@if($summary['unrecorded'])<small>{{ $summary['unrecorded'] }} reservation total(s) not recorded; excluded from Total.</small>@endif</div>
            <details class="payment-download"><summary>Download <span aria-hidden="true">▾</span></summary><div>@foreach(['pdf'=>'PDF','xlsx'=>'Excel','csv'=>'CSV'] as $format=>$label)<a data-payment-download href="{{ route('payments.export', array_merge(request()->only('search','status','from_date','to_date','sort','direction'), ['format'=>$format])) }}">{{ $label }}</a>@endforeach</div></details>
        </div>
        <div class="reservation-table-toolbar"><label for="payment-rows">Show <select id="payment-rows" name="per_page" form="payment-filters" data-table-filter>@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($payments->perPage()===$size)>{{ $size }}</option>@endforeach</select> entries</label><p>Showing {{ $payments->firstItem() ?? 0 }} to {{ $payments->lastItem() ?? 0 }} of {{ $payments->total() }} payments</p></div>
        <div class="reservation-datatable-scroll" tabindex="0" aria-label="Payments table; scroll horizontally on small screens"><table class="reservation-datatable" aria-label="Payments"><thead><tr>
            @foreach(['id'=>'Payment ID','reservation'=>'Reservation ID','resident'=>'Resident','resource'=>'Resource','date'=>'Reservation Date','total'=>'Total','status'=>'Payment Status'] as $sort=>$label)
                @php($active=(request('sort') ?: 'id')===$sort)
                @php($ascending=(request('direction') ?: 'desc')==='asc')
                <th scope="col" aria-sort="{{ $active ? ($ascending ? 'ascending' : 'descending') : 'none' }}"><a data-table-link href="{{ route('payments.index', array_merge(request()->except('page'), ['sort'=>$sort,'direction'=>$active && $ascending ? 'desc' : 'asc'])) }}">{{ $label }} <span aria-hidden="true">{{ $active ? ($ascending ? '↑' : '↓') : '↕' }}</span></a></th>
            @endforeach<th scope="col">Actions</th>
        </tr></thead><tbody>
            @forelse($payments as $payment)
                @php($row=\App\Support\PaymentReport::row($payment))
                <tr data-payment-id="{{ $payment->id }}"><td>#{{ $row[0] }}</td><td>#{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}@if(auth()->user()->role==='super_admin')<small>{{ $payment->reservation->managingBarangay() }}</small>@endif</td><td>{{ $payment->reservation->period()->start->format('M j, Y') }}</td><td>{{ \App\Support\Money::format($row[5]) }}</td><td><span class="reservation-status payment-status-{{ $payment->payment_status }}">{{ $row[6] }}</span></td><td><details class="reservation-table-actions"><summary aria-label="Actions for Payment {{ $payment->id }}"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></summary><div class="reservation-table-menu"><button type="button" data-reservation-open="payment-view-{{ $payment->id }}">View</button><button type="button" data-reservation-open="payment-edit-{{ $payment->id }}">Edit</button></div></details></td></tr>
            @empty<tr><td colspan="8">No payment records found.</td></tr>@endforelse
        </tbody></table></div>
        <nav class="reservation-table-pagination" aria-label="Payments table pagination">
            @if($payments->previousPageUrl())<a data-table-link href="{{ $payments->previousPageUrl() }}">Previous</a>@else<span aria-disabled="true">Previous</span>@endif
            @foreach($payments->getUrlRange(max(1,$payments->currentPage()-2),min($payments->lastPage(),$payments->currentPage()+2)) as $page=>$url)<a data-table-link href="{{ $url }}" @if($page===$payments->currentPage()) aria-current="page" @endif>{{ $page }}</a>@endforeach
            @if($payments->nextPageUrl())<a data-table-link href="{{ $payments->nextPageUrl() }}">Next</a>@else<span aria-disabled="true">Next</span>@endif
        </nav>
        @foreach($payments as $payment)@include('payments.dialogs')@endforeach
        <noscript><p class="reservation-alert">Enable JavaScript to view and edit payments in modals.</p></noscript>
    </div>
    <script src="{{ asset('js/reservation-datatable.js') }}?v={{ filemtime(public_path('js/reservation-datatable.js')) }}" defer></script>
</x-layouts.user>
