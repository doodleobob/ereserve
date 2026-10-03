@php($managingAdmins = $accountRoute === 'admins')
@php($heading = $managingAdmins ? 'Admin Management' : 'Resident Management')
<x-layouts.user :title="$heading . ' - eReserve'" :active="$accountRoute">
    <section class="page-heading admin-facility-heading">
        <div><h2>{{ $heading }}</h2><p>{{ $managingAdmins ? 'Manage barangay admin accounts' : 'Manage residents in ' . auth()->user()->barangay }}</p></div>
        @if ($managingAdmins)<button type="button" class="profile-primary-button" data-modal-open="create-admin">Create Admin</button>@endif
    </section>
    @if (session('account_status'))<p class="reservation-alert" role="status">{{ session('account_status') }}</p>@endif
    <form method="GET" action="{{ route($accountRoute . '.index') }}" class="filter-card admin-reservation-filter-card">
        <div class="filter-group admin-search-group">
            <label for="search">Search by name/email</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="255">
        </div>
        @if ($managingAdmins)
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
        <button class="profile-primary-button" type="submit">Apply</button>
        <a class="profile-secondary-button" href="{{ route($accountRoute . '.index') }}">Reset</a>
    </form>
    <section class="content-card">
        <div class="admin-reservation-table"><table>
            <thead><tr><th>Name</th><th>Email</th><th>Phone Number</th><th>{{ $managingAdmins ? 'Assigned Barangay' : 'Barangay' }}</th><th>Account Status</th><th>{{ $managingAdmins ? 'Date Created' : 'Date Registered' }}</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr><td>{{ $account->name }}</td><td>{{ $account->email }}</td><td>{{ $account->phone_number ?? 'Not provided' }}</td><td>{{ $account->barangay }}</td><td>{{ $account->is_active ? 'Active' : 'Inactive' }}</td><td>{{ $account->created_at?->format('M j, Y') }}</td>
                        <td><a href="{{ route($accountRoute . '.show', $account) }}" data-account-view>View</a> @include('accounts.status-action')</td></tr>
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
