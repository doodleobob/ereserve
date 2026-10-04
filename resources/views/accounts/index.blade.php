@php($managingAdmins = $accountRoute === 'admins')
@php($heading = $managingAdmins ? 'Admin Management' : 'Resident Management')
<x-layouts.user :title="$heading . ' - eReserve'" :active="$accountRoute">
    <section class="page-heading page-header">
        <div><h2>{{ $heading }}</h2><p>{{ $managingAdmins ? 'Manage barangay admin accounts' : ($canManageAccounts ? 'Manage residents in ' . auth()->user()->barangay : 'View residents across all barangays (read-only).') }}</p></div>
        @if ($managingAdmins)<button type="button" class="button button-primary button-create" data-modal-open="create-admin"><x-add-icon />Add Admin</button>@endif
    </section>
    @if (session('account_status'))<p class="reservation-alert" role="status">{{ session('account_status') }}</p>@endif
    <form method="GET" action="{{ route($accountRoute . '.index') }}" class="filter-card filter-toolbar" aria-label="{{ $heading }} filters">
        @foreach (['sort', 'direction'] as $key)
            @if (request()->filled($key))<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif
        @endforeach
        <div class="filter-group admin-search-group">
            <label for="search">Search by name/email</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="255">
        </div>
        @if (auth()->user()->role === 'super_admin')
            <div class="filter-group"><label for="barangay">Barangay</label><select id="barangay" name="barangay">
                <option value="">All barangays</option>
                @foreach ($barangays as $barangay)<option @selected(($filters['barangay'] ?? '') === $barangay)>{{ $barangay }}</option>@endforeach
            </select></div>
        @endif
        <div class="filter-group"><label for="status">Account Status</label><select id="status" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
        </select></div>
        <div class="filter-actions">
            <button class="button button-primary" type="submit">Apply</button>
            <a class="button button-secondary" href="{{ route($accountRoute . '.index') }}">Reset</a>
        </div>
    </form>
    <section class="content-card account-management-card">
        <div class="admin-reservation-table"><table>
            <thead><tr>
                @foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone Number', 'barangay' => $managingAdmins ? 'Assigned Barangay' : 'Barangay', 'status' => 'Account Status', 'created' => $managingAdmins ? 'Date Created' : 'Date Registered'] as $sort => $label)
                    @php($sortable = $sort !== 'phone')
                    @php($active = (request('sort') ?: 'name') === $sort)
                    @php($ascending = (request('direction') ?: 'asc') === 'asc')
                    <th scope="col" @if($sortable) aria-sort="{{ $active ? ($ascending ? 'ascending' : 'descending') : 'none' }}" @endif>
                        @if($sortable)
                            <a href="{{ route($accountRoute . '.index', array_merge(request()->except('page'), ['sort' => $sort, 'direction' => $active && $ascending ? 'desc' : 'asc'])) }}">{{ $label }} @if($active)<span aria-hidden="true">{{ $ascending ? '↑' : '↓' }}</span>@endif</a>
                        @else
                            {{ $label }}
                        @endif
                    </th>
                @endforeach
                <th scope="col">Actions</th>
            </tr></thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr><td>{{ $account->name }}</td><td>{{ $account->email }}</td><td>{{ $account->phone_number ?? 'Not provided' }}</td><td>{{ $account->barangay }}</td><td>{{ $account->is_active ? 'Active' : 'Inactive' }}</td><td>{{ $account->created_at?->format('M j, Y') }}</td>
                        <td><div class="table-actions"><a class="button button-secondary button-compact" href="{{ route($accountRoute . '.show', $account) }}" data-account-view>View</a> @include('accounts.status-action')</div></td></tr>
                @empty
                    <tr><td colspan="7">No accounts match your filters.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @include('accounts.pagination', ['paginator' => $accounts])
    </section>
    <x-modal id="account-details" title="{{ $managingAdmins ? 'Admin Details' : 'Resident Details' }}" size="large">
        <div class="facility-modal-body" data-account-detail-body></div>
        <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Close</button></div>
    </x-modal>
    @if($managingAdmins)
        <x-modal id="create-admin" title="Create Admin" size="large">
            <form method="POST" action="{{ route('admins.store') }}" data-modal-action>@csrf
                <div class="facility-modal-body">@include('admins.form-fields')<p data-action-error role="alert" hidden></p></div>
                <div class="facility-modal-actions"><button type="button" class="facility-modal-secondary" data-modal-close>Cancel</button><button type="submit" class="facility-modal-primary">Create Admin</button></div>
            </form>
        </x-modal>
    @endif
    @include('accounts.confirmation')
</x-layouts.user>
