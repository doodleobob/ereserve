<x-layouts.user title="Audit Log - eReserve" active="audit-logs">
    <section class="page-heading reservations-heading">
        <h2>Administrative Audit Log</h2>
        <p>Review administrative changes across all barangays.</p>
    </section>
    <form method="GET" action="{{ route('super-admin.audit-logs.index') }}" class="filter-card filter-toolbar" aria-label="Audit log filters">
        <div class="filter-group"><label for="audit-search">Search</label><input id="audit-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="150" placeholder="Actor, action, or record"></div>
        <div class="filter-group"><label for="audit-barangay">Barangay</label><select id="audit-barangay" name="barangay"><option value="">All barangays</option>@foreach($barangays as $barangay)<option value="{{ $barangay }}" @selected(($filters['barangay'] ?? '') === $barangay)>{{ $barangay }}</option>@endforeach</select></div>
        <div class="filter-group"><label for="audit-role">Actor role</label><select id="audit-role" name="actor_role"><option value="">All administrators</option>@foreach(['super_admin' => 'Super Admin', 'admin' => 'Barangay Admin'] as $role => $label)<option value="{{ $role }}" @selected(($filters['actor_role'] ?? '') === $role)>{{ $label }}</option>@endforeach</select></div>
        <div class="filter-group"><label for="audit-action">Action</label><select id="audit-action" name="action"><option value="">All actions</option>@foreach($actions as $key => $label)<option value="{{ $key }}" @selected(($filters['action'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="filter-group"><label for="audit-from">From</label><input id="audit-from" type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}"></div>
        <div class="filter-group"><label for="audit-to">To</label><input id="audit-to" type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}"></div>
        <div class="filter-actions"><button class="button button-primary" type="submit">Apply</button><a class="button button-secondary" href="{{ route('super-admin.audit-logs.index') }}">Reset</a></div>
    </form>
    <section class="content-card">
        <div class="reservation-table-toolbar"><p role="status">Showing {{ $entries->firstItem() ?? 0 }} to {{ $entries->lastItem() ?? 0 }} of {{ $entries->total() }} audit entries</p></div>
        <x-management-table label="Administrative Audit Log">
            <thead><tr><th scope="col">Date and time</th><th scope="col">Administrator</th><th scope="col">Role</th><th scope="col">Barangay</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Actions</th></tr></thead>
            <tbody>@forelse($entries as $entry)
                <tr><td>{{ $entry->occurred_at->timezone(config('app.timezone'))->format('M j, Y g:i:s A') }}</td><td>{{ $entry->actor_name }}{{ $entry->actor_id ? '' : ' (deleted account)' }}</td><td>{{ $entry->actor_role === 'super_admin' ? 'Super Admin' : 'Barangay Admin' }}</td><td>{{ $entry->barangay ?? 'System-wide' }}</td><td>{{ $entry->description }}</td><td>@if($entry->target_type){{ $entry->target_type }}@else&mdash;@endif{{ $entry->target_id ? ' #'.$entry->target_id : '' }}</td><td><x-table-actions id="audit-actions-{{ $entry->id }}" label="Actions for audit entry {{ $entry->id }}"><a class="button button-secondary button-compact" href="{{ route('super-admin.audit-logs.show', $entry) }}">View details</a></x-table-actions></td></tr>
            @empty<tr><td colspan="7">No audit entries match your filters.</td></tr>@endforelse</tbody>
        </x-management-table>
        <x-table-pagination :paginator="$entries" label="Audit log pagination" />
    </section>
</x-layouts.user>
