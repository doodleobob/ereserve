<form method="POST" action="{{ route($accountRoute . '.status', $account) }}" data-account-status data-account-name="{{ $account->name }}">
    @csrf
    @method('PATCH')
    <input type="hidden" name="is_active" value="{{ $account->is_active ? '0' : '1' }}">
    <button type="submit" @class(['button', 'button-danger' => $account->is_active, 'button-primary' => ! $account->is_active]) disabled>{{ $account->is_active ? 'Deactivate' : 'Activate' }}</button>
</form>
