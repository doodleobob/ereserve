<form method="POST" action="{{ route($accountRoute . '.status', $account) }}" @if ($account->is_active) data-deactivate-account @endif>
    @csrf
    @method('PATCH')
    <input type="hidden" name="is_active" value="{{ $account->is_active ? '0' : '1' }}">
    <button type="submit" class="profile-secondary-button" @disabled($account->is_active)>{{ $account->is_active ? 'Deactivate' : 'Activate' }}</button>
</form>
