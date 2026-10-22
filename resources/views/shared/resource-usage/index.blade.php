<x-layouts.user title="Resource Usage - eReserve" active="resource-usage">
    @php($columns = \App\Support\ResourceUsageReport::columns($systemWide))
    <section class="page-heading reservations-heading"><h2>Resource Usage</h2><p>{{ $systemWide ? 'Monitor resource usage across all barangays (read-only).' : 'Confirm facility visits, equipment handovers and returns.' }}</p></section>
    @if(session('usage_status'))<div class="reservation-alert" role="status">{{ session('usage_status') }}</div>@endif
    @if($errors->any())<div class="reservation-alert reservation-alert-error" role="alert">{{ $errors->first() }}</div>@endif
    <div data-resource-usage-table data-page="{{ $records->currentPage() }}">
        <form method="GET" action="{{ route('resource-usage.index') }}" class="filter-card filter-toolbar payment-filters" id="resource-usage-filters" aria-label="Resource usage filters">
            @foreach(['sort','direction'] as $key)@if(request()->filled($key))<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif @endforeach
            <div class="filter-group"><label for="search">Search</label><input id="search" type="search" name="search" maxlength="200" value="{{ request('search') }}" placeholder="Usage ID, reservation, resident, resource"></div>
            @if($systemWide)<div class="filter-group"><label for="usage-barangay">Barangay</label><select id="usage-barangay" name="barangay" data-auto-submit><option value="">All Barangays</option>@foreach(\App\Support\Barangays::ALL as $barangay)<option value="{{ $barangay }}" @selected(request('barangay') === $barangay)>{{ $barangay }}</option>@endforeach</select></div>@endif
            <div class="filter-group"><label for="usage-type">Resource Type</label><select id="usage-type" name="type" data-auto-submit><option value="">All Types</option>@foreach(['Facility','Equipment'] as $type)<option @selected(request('type') === $type)>{{ $type }}</option>@endforeach</select></div>
            <div class="filter-group"><label for="usage-status">Usage Status</label><select id="usage-status" name="status" data-auto-submit><option value="">All Statuses</option>@foreach(\App\Models\ResourceUsage::STATUSES as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="filter-group"><label for="from-date">From Date</label><input id="from-date" type="date" name="from_date" value="{{ request('from_date') }}" data-auto-submit></div>
            <div class="filter-group"><label for="to-date">To Date</label><input id="to-date" type="date" name="to_date" value="{{ request('to_date') }}" data-auto-submit></div>
            <div class="filter-group"><label for="usage-overdue">Overdue</label><select id="usage-overdue" name="overdue" data-auto-submit><option value="">All Records</option><option value="1" @selected(request('overdue') === '1')>Overdue Only</option></select></div>
            <div class="filter-group"><label for="usage-outstanding">Borrowed Equipment</label><select id="usage-outstanding" name="outstanding" data-auto-submit><option value="">All Records</option><option value="1" @selected(request('outstanding') === '1')>Outstanding Only</option></select></div>
            <div class="filter-actions"><button type="submit" class="reservation-table-search">Search</button><a class="button button-secondary" data-table-link href="{{ route('resource-usage.index') }}">Reset</a></div>
            <p class="payment-date-help">Dates filter scheduled starts. Times use {{ config('app.timezone') }}. Actual usage begins only after administrator confirmation.</p>
        </form>
        <div class="reservation-table-toolbar">
            <label for="usage-rows">Show <select id="usage-rows" name="per_page" form="resource-usage-filters" data-table-filter>@foreach([10,25,50,100] as $size)<option @selected($records->perPage() === $size)>{{ $size }}</option>@endforeach</select> entries</label>
            <p role="status">Showing {{ $records->firstItem() ?? 0 }} to {{ $records->lastItem() ?? 0 }} of {{ $records->total() }} records</p>
            <details class="payment-download"><summary>Download <span aria-hidden="true">&#9662;</span></summary><div>@foreach(['pdf'=>'PDF','xlsx'=>'Excel'] as $format=>$label)<a data-usage-download href="{{ route('resource-usage.export', array_merge(request()->except('page','per_page'), ['format'=>$format])) }}">{{ $label }}</a>@endforeach</div></details>
        </div>
        <div class="reservation-datatable-scroll" tabindex="0" aria-label="Resource usage table; scroll horizontally on small screens"><table class="reservation-datatable" aria-label="Resource Usage"><thead><tr>
            @foreach($columns as $sort=>$label)
                @php($active=(request('sort') ?: 'reservation') === $sort)
                @php($ascending=(request('direction') ?: 'desc') === 'asc')
                <th scope="col" aria-sort="{{ $active ? ($ascending ? 'ascending' : 'descending') : 'none' }}"><a data-table-link href="{{ route('resource-usage.index', array_merge(request()->except('page'), ['sort'=>$sort,'direction'=>$active && $ascending ? 'desc' : 'asc'])) }}">{{ $label }} <span aria-hidden="true">{{ $active ? ($ascending ? '↑' : '↓') : '↕' }}</span></a></th>
            @endforeach<th scope="col">Actions</th></tr></thead><tbody>
            @forelse($records as $reservation)
                @php($row=\App\Support\ResourceUsageReport::row($reservation, $systemWide))
                @php($usage=$reservation->resourceUsage)
                <tr data-usage-reservation="{{ $reservation->id }}">
                    @foreach($row as $key=>$value)<td>@if($key === 'status')<span class="reservation-status reservation-status-{{ in_array($reservation->usage_status,['completed','returned']) ? 'accepted' : 'pending' }}">{{ $value }}</span>@elseif($key === 'overdue' && $value === 'Overdue')<span class="reservation-status reservation-status-rejected">Overdue</span>@else{{ $value }}@endif</td>@endforeach
                    <td><x-table-actions id="usage-actions-{{ $reservation->id }}" label="Actions for reservation {{ $reservation->id }}">
                        <button type="button" data-reservation-open="usage-details-{{ $reservation->id }}">View Details</button>
                        <button type="button" data-reservation-open="usage-history-{{ $reservation->id }}">View History</button>
                        @can('operate', [\App\Models\ResourceUsage::class, $reservation])
                            @foreach(\App\Support\ResourceUsagePresentation::actions($reservation) as $action=>$label)<button type="button" class="button-primary" data-reservation-open="usage-{{ $action }}-{{ $reservation->id }}">{{ $label }}</button>@endforeach
                        @endcan
                    </x-table-actions></td>
                </tr>
            @empty<tr><td colspan="{{ count($columns)+1 }}">No usage records match the selected filters.</td></tr>@endforelse
        </tbody></table></div>
        <x-table-pagination :paginator="$records" label="Resource usage pagination" />
        @foreach($records as $reservation)@include('shared.resource-usage.dialogs')@endforeach
        <noscript><p class="reservation-alert">Enable JavaScript to view details and confirm resource usage in modals.</p></noscript>
    </div>
    @push('scripts')<script src="{{ asset('js/reservation-datatable.js') }}?v={{ filemtime(public_path('js/reservation-datatable.js')) }}" defer></script>@endpush
</x-layouts.user>
