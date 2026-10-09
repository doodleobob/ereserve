@php
    // Keep the existing layout's active prop as a fallback for isolated view previews.
    $drawerActive = match (true) {
        request()->routeIs('dashboard') => 'dashboard',
        request()->routeIs('calendar') => $isAdmin ? 'calendar' : 'dashboard',
        request()->routeIs('facilities', 'facilities.*') => 'facilities',
        request()->routeIs('reservations.*') => 'reservations',
        request()->routeIs('official-uses.*') => 'official-uses',
        request()->routeIs('payments.*') => 'payments',
        request()->routeIs('residents.*') => 'residents',
        request()->routeIs('admins.*') => 'admins',
        request()->routeIs('analytics', 'super-admin.analytics') => 'analytics',
        request()->routeIs('profile.*', 'settings.security') => 'profile',
        default => ! $isAdmin && $active === 'calendar' ? 'dashboard' : $active,
    };
@endphp

<dialog id="mobile-navigation" class="mobile-drawer" aria-label="eReserve navigation" aria-modal="true">
    <div class="mobile-drawer-heading">
        <strong class="mobile-drawer-title">eReserve</strong>
        <button type="button" class="mobile-drawer-close" aria-label="Close navigation menu" autofocus>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
        </button>
    </div>
    <div class="mobile-drawer-scroll">
        <div class="mobile-drawer-profile">
            <span class="mobile-drawer-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($user->name), 0, 1)) }}</span>
            <strong>{{ $user->name }}</strong>
            <span>{{ $user->email }}</span>
            @if (filled($user->barangay))
                <span>Barangay: {{ $user->barangay }}</span>
            @endif
        </div>
        <nav class="mobile-drawer-nav" aria-label="Account navigation">
            @include('partials.user-navigation', ['mobile' => true, 'active' => $drawerActive])
        </nav>
    </div>
    <div class="mobile-drawer-footer">
        @include('partials.logout')
    </div>
</dialog>
