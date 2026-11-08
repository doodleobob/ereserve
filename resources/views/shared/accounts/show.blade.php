@php($managingAdmins = $accountRoute === 'admins')
<x-layouts.user :title="($managingAdmins ? 'Admin Details' : 'Resident Details') . ' - eReserve'" :active="$accountRoute">
    <section class="page-heading"><h2>{{ $managingAdmins ? 'Admin Details' : 'Resident Details' }}</h2><p><a href="{{ route($accountRoute . '.index') }}">Back to {{ $managingAdmins ? 'Admin Management' : 'Resident Management' }}</a></p></section>
    @if (session('account_status'))<p class="reservation-alert" role="status">{{ session('account_status') }}</p>@endif
    @include('shared.accounts.partials.details')
    @include('shared.accounts.partials.confirmation')
    @if ($accountRoute === 'admins' && $canManageAccounts)
        <button type="button" class="button button-secondary" data-modal-open="admin-password-reset-confirmation">Send Password Reset Link</button>
        <x-modal id="admin-password-reset-confirmation" title="Send Password Reset Link" size="small">
            <form method="POST" action="{{ route('admins.password-reset', $account) }}" data-modal-action>
                @csrf
                <div class="facility-modal-body">
                    <p>A secure reset link will be sent to {{ $account->email }}. The password and reset token will not be shown.</p>
                    <p data-action-error role="alert" hidden></p>
                </div>
                <div class="facility-modal-actions">
                    <button type="button" class="facility-modal-secondary" data-modal-close autofocus>Cancel</button>
                    <button type="submit" class="facility-modal-primary">Send Reset Link</button>
                </div>
            </form>
        </x-modal>
    @endif
</x-layouts.user>
