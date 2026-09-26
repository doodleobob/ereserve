@php($managingAdmins = $accountRoute === 'admins')
<x-layouts.user :title="($managingAdmins ? 'Admin Details' : 'Resident Details') . ' - eReserve'" :active="$accountRoute">
    <section class="page-heading"><h2>{{ $managingAdmins ? 'Admin Details' : 'Resident Details' }}</h2><p><a href="{{ route($accountRoute . '.index') }}">Back to {{ $managingAdmins ? 'Admin Management' : 'Resident Management' }}</a></p></section>
    @if (session('account_status'))<p class="reservation-alert" role="status">{{ session('account_status') }}</p>@endif
    <section class="profile-card">
        <h3>{{ $managingAdmins ? 'Admin Information' : 'Resident Information' }}</h3>
        <dl class="account-info-list">
            <div><dt>Name</dt><dd>{{ $account->name }}</dd></div>
            <div><dt>Email</dt><dd>{{ $account->email }}</dd></div>
            <div><dt>Phone Number</dt><dd>{{ $account->phone_number ?? 'Not provided' }}</dd></div>
            <div><dt>Barangay</dt><dd>{{ $account->barangay }}</dd></div>
            <div><dt>Account Status</dt><dd>{{ $account->is_active ? 'Active' : 'Inactive' }}</dd></div>
            <div><dt>{{ $managingAdmins ? 'Date Created' : 'Registration Date' }}</dt><dd>{{ $account->created_at?->format('M j, Y') }}</dd></div>
        </dl>
        @include('accounts.status-action')
    </section>
    @if ($reservations !== null)
        <section class="content-card admin-schedule-card">
            <h3>Reservation Activity</h3>
            <div class="admin-reservation-table"><table>
                <thead><tr><th>Facility/Equipment</th><th>Reservation Date</th><th>Start Time</th><th>End Time</th><th>Reservation Status</th><th>Total Amount</th><th>Recorded Payment</th></tr></thead>
                <tbody>
                    @forelse ($reservations as $reservation)
                        <tr>
                            <td>{{ $reservation->facility_name }}</td><td>{{ $reservation->reservation_date }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($reservation->start_time)->format('g:i A') }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($reservation->end_time)->format('g:i A') }}</td>
                            <td>{{ ucfirst($reservation->status) }}</td>
                            <td>{{ $reservation->calculatedAmount() === null ? 'Not recorded' : \App\Support\Money::format($reservation->calculatedAmount()) }}</td>
                            <td>{{ $reservation->total_payment === null ? 'Not recorded' : \App\Support\Money::format($reservation->total_payment) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No reservation activity.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            @include('accounts.pagination', ['paginator' => $reservations])
        </section>
    @endif
    @include('accounts.confirmation')
</x-layouts.user>
