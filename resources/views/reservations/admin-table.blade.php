<div class="reservation-table-toolbar">
    <label for="reservation-rows">Show <select id="reservation-rows" name="per_page" form="reservation-filters" data-table-filter>@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($reservations->perPage()===$size)>{{ $size }}</option>@endforeach</select> entries</label>
    <p role="status">Showing {{ $reservations->firstItem() ?? 0 }} to {{ $reservations->lastItem() ?? 0 }} of {{ $reservations->total() }} reservations</p>
</div>
<div class="reservation-datatable-scroll" tabindex="0" aria-label="Reservation table; scroll horizontally on small screens">
<table class="reservation-datatable" aria-label="Reservation Management">
    <thead><tr>
    @foreach(['id'=>'Reservation ID','resident'=>'Resident','facility'=>'Resource','date'=>'Reservation Date','time'=>'Time','amount'=>'Total','status'=>'Status'] as $sort=>$label)
        @php($active=request('sort','date')===$sort)
        @php($direction=$active && request('direction','desc')==='asc' ? 'desc' : 'asc')
        <th scope="col" aria-sort="{{ $active ? (request('direction','desc')==='asc' ? 'ascending' : 'descending') : 'none' }}"><a data-table-link href="{{ route('reservations.index',array_merge(request()->except('page'),['sort'=>$sort,'direction'=>$direction])) }}">{{ $label }} <span aria-hidden="true">{{ $active ? (request('direction','desc')==='asc' ? '↑' : '↓') : '↕' }}</span></a></th>
    @endforeach
        <th scope="col">Actions</th>
    </tr></thead>
    <tbody>
    @forelse($reservations as $reservation)
        <tr data-reservation-id="{{ $reservation->id }}">
            <td>#{{ $reservation->id }}</td>
            <td>{{ $reservation->requester_name ?? 'Not available' }}</td>
            <td>{{ $reservation->facility_name }}<small>{{ $reservation->category }}</small></td>
            <td>{{ $reservation->period()->start->format('M j, Y') }}</td>
            <td>{{ $reservation->period()->start->format('g:i A') }} – {{ $reservation->period()->end->format('g:i A') }}<small>{{ $reservation->period()->endDateLabel() }}</small></td>
            <td>{{ \App\Support\Money::format($reservation->total_payment) }}</td>
            <td><span class="reservation-status reservation-status-{{ $reservation->status }}">{{ ucfirst($reservation->status) }}</span>@include('reservations.official-use-conflicts')</td>
            <td><details class="reservation-table-actions"><summary aria-label="Actions for reservation {{ $reservation->id }}"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></summary><div class="reservation-table-menu">
                <button type="button" data-reservation-open="view-{{ $reservation->id }}">View</button>
                @can('manage',$reservation)
                    @if($reservation->status==='pending')
                        <button type="button" form="accept-source-{{ $reservation->id }}" data-confirm-payment data-allow-empty="true" data-accept-url="{{ route('reservations.accept',$reservation) }}" data-facility="{{ $reservation->facility_name }}" data-resident="{{ $reservation->requester_name }}" data-schedule="{{ $reservation->period()->start->format('M j, Y g:i A') }} – {{ $reservation->period()->end->format('M j, Y g:i A') }}">Accept</button>
                        <button type="button" data-reservation-open="reject-{{ $reservation->id }}">Reject</button>
                    @elseif($reservation->status==='accepted')
                        <button type="button" data-reservation-open="edit-{{ $reservation->id }}">Edit</button>
                    @endif
                @endcan
            </div></details></td>
        </tr>
    @empty
        <tr><td colspan="8">No reservations match the selected filters.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<nav class="reservation-table-pagination" aria-label="Reservation table pagination">
    @if($reservations->previousPageUrl())<a data-table-link href="{{ $reservations->previousPageUrl() }}">Previous</a>@else<span aria-disabled="true">Previous</span>@endif
    @foreach($reservations->getUrlRange(max(1,$reservations->currentPage()-2),min($reservations->lastPage(),$reservations->currentPage()+2)) as $page=>$url)
        <a data-table-link href="{{ $url }}" @if($page===$reservations->currentPage()) aria-current="page" @endif>{{ $page }}</a>
    @endforeach
    @if($reservations->nextPageUrl())<a data-table-link href="{{ $reservations->nextPageUrl() }}">Next</a>@else<span aria-disabled="true">Next</span>@endif
</nav>
@foreach($reservations as $reservation)
    @include('reservations.admin-dialogs')
@endforeach
