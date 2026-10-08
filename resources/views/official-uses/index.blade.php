<x-layouts.user title="Official Use - eReserve" active="official-uses">
    <section class="page-heading page-header reservations-heading">
        <div><h2>Official Use</h2>
        <p>Schedule barangay/government use of facilities and equipment.</p></div>
        <button type="button" class="button button-primary button-create" data-reservation-open="add-official-use" aria-label="+ Add Official Use"><x-add-icon />Add Official Use</button>
    </section>
    <div data-official-use-table data-page="{{ $uses->currentPage() }}">
        <form method="GET" action="{{ route('official-uses.index') }}" class="filter-card filter-toolbar official-use-filters" id="official-use-filters" aria-label="Official Use filters">
            @foreach(['sort', 'direction'] as $key)
                @if(request()->filled($key))<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif
            @endforeach
            <div class="filter-group"><label for="search">Search</label><input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Search ID, resource, or purpose..."></div>
            <div class="filter-group"><label for="resource">Resource</label><select id="resource" name="facility_id" data-auto-submit><option value="">All Resources</option>@foreach($resources as $resource)<option value="{{ $resource->id }}" @selected(request('facility_id') == $resource->id)>{{ $resource->name }}{{ auth()->user()->role === 'super_admin' ? ' — '.$resource->barangay : '' }}</option>@endforeach</select></div>
            <div class="filter-group"><label for="status">Status</label><select id="status" name="status" data-auto-submit><option value="all">All Statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="conflict" @selected(request('status') === 'conflict')>Conflict</option></select></div>
            <div class="filter-group"><label for="from-date">From Date</label><input id="from-date" name="from_date" type="date" value="{{ request('from_date') }}" data-auto-submit></div>
            <div class="filter-group"><label for="to-date">To Date</label><input id="to-date" name="to_date" type="date" value="{{ request('to_date') }}" data-auto-submit></div>
            <div class="filter-actions"><button type="submit" class="reservation-table-search">Search</button></div>
        </form>
        <div class="reservation-table-toolbar">
            <label for="official-use-rows">Show <select id="official-use-rows" name="per_page" form="official-use-filters" data-table-filter>@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($uses->perPage() === $size)>{{ $size }}</option>@endforeach</select> entries</label>
            <p role="status">Showing {{ $uses->firstItem() ?? 0 }} to {{ $uses->lastItem() ?? 0 }} of {{ $uses->total() }} official uses</p>
        </div>
        <div class="reservation-datatable-scroll" tabindex="0" aria-label="Official Use table; scroll horizontally on small screens">
            <table class="reservation-datatable" aria-label="Official Use">
                <thead><tr>
                    @foreach(['id'=>'Official Use ID', 'resource'=>'Resource', 'schedule'=>'Schedule', 'purpose'=>'Purpose', 'status'=>'Status'] as $sort=>$label)
                        @php($active = request('sort', 'schedule') === $sort)
                        @php($direction = $active && request('direction', 'desc') === 'asc' ? 'desc' : 'asc')
                        <th scope="col" aria-sort="{{ $active ? (request('direction', 'desc') === 'asc' ? 'ascending' : 'descending') : 'none' }}"><a data-table-link href="{{ route('official-uses.index', array_merge(request()->except('page'), ['sort'=>$sort, 'direction'=>$direction])) }}">{{ $label }} <span aria-hidden="true">{{ $active ? (request('direction', 'desc') === 'asc' ? '↑' : '↓') : '↕' }}</span></a></th>
                    @endforeach
                    <th scope="col">Actions</th>
                </tr></thead>
                <tbody>
                    @forelse($uses as $use)
                        <tr data-official-use-id="{{ $use->id }}">
                            <td>#{{ $use->id }}</td>
                            <td>{{ $use->facility->name }}<small>{{ $use->facility->category }}{{ auth()->user()->role === 'super_admin' ? ' — '.$use->barangay : '' }}</small></td>
                            <td>{{ $use->period()->start->format('M j, Y') }}<small>{{ $use->period()->start->format('g:i A') }} – {{ $use->period()->end->format('g:i A') }} {{ $use->period()->endDateLabel() }}</small></td>
                            <td>{{ $use->purpose }}</td>
                            <td><span class="reservation-status official-use-status-{{ $use->status }}">{{ ucfirst($use->status) }}</span></td>
                            <td><details class="reservation-table-actions"><summary aria-label="Actions for Official Use {{ $use->id }}"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></summary><div class="reservation-table-menu">
                                <button type="button" data-reservation-open="official-use-view-{{ $use->id }}">View</button>
                                @if(in_array($use->status, ['active', 'conflict'], true))
                                    <button type="button" data-reservation-open="official-use-edit-{{ $use->id }}">Edit</button>
                                @endif
                            </div></details></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No official uses match the selected filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-table-pagination :paginator="$uses" label="Official Use table pagination" />
        @foreach($uses as $use)
            @include('official-uses.dialogs')
        @endforeach
        <x-modal id="add-official-use" title="Add Official Use" size="medium">
            <form method="POST" action="{{ route('official-uses.store') }}" data-reservation-action>@csrf
                <div class="facility-modal-body">
                    <label>Resource<select name="facility_id" required><option value="">Select Resource</option>@foreach($resources as $resource)<option value="{{ $resource->id }}">{{ $resource->name }}{{ auth()->user()->role === 'super_admin' ? ' — '.$resource->barangay : '' }}</option>@endforeach</select></label>
                    <label>Date<input name="date" type="date" min="{{ today()->toDateString() }}" required></label>
                    <label>Start Time<input name="start_time" type="time" required></label>
                    <label>End Time<input name="end_time" type="time" required></label>
                    <p>An earlier End Time ends the next day. Overlapping pending requests will be cancelled. Accepted residents will be notified to choose their preference.</p>
                    <label>Purpose<textarea name="purpose" maxlength="500" rows="3" required></textarea></label>
                    <p data-action-error role="alert" hidden></p>
                </div>
                <div class="facility-modal-actions"><button type="button" data-reservation-close class="facility-modal-secondary">Cancel</button><button type="submit" class="facility-modal-primary">Save Official Use</button></div>
            </form>
        </x-modal>
        <noscript><p class="reservation-alert">Enable JavaScript to add Official Use through the modal.</p></noscript>
    </div>
    @push('scripts')
        <script src="{{ asset('js/reservation-datatable.js') }}?v={{ filemtime(public_path('js/reservation-datatable.js')) }}" defer></script>
    @endpush
</x-layouts.user>
