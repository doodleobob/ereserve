@php($managingAdmins = $accountRoute === 'admins')
<x-layouts.user :title="($managingAdmins ? 'Admin Details' : 'Resident Details') . ' - eReserve'" :active="$accountRoute">
    <section class="page-heading"><h2>{{ $managingAdmins ? 'Admin Details' : 'Resident Details' }}</h2><p><a href="{{ route($accountRoute . '.index') }}">Back to {{ $managingAdmins ? 'Admin Management' : 'Resident Management' }}</a></p></section>
    @if (session('account_status'))<p class="reservation-alert" role="status">{{ session('account_status') }}</p>@endif
    @include('accounts.details')
    @include('accounts.confirmation')
</x-layouts.user>
