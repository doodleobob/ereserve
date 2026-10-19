<a class="header-icon-button profile-button {{ $active === 'profile' ? 'active' : '' }}"
   href="{{ route('profile.edit') }}" aria-label="Profile Settings" title="Profile Settings"
   @if ($active === 'profile') aria-current="page" @endif>
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="6" r="4" /><path d="M3 21v-2a9 9 0 0 1 18 0v2" /></svg>
</a>
