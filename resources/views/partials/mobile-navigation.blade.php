@php
    // Keep the existing layout's active prop as a fallback for isolated view previews.
    $drawerActive = match (true) {
        request()->routeIs('dashboard') => 'dashboard',
        request()->routeIs('calendar') => 'calendar',
        request()->routeIs('equipment') => 'equipment',
        request()->routeIs('facilities', 'facilities.*') => $active === 'equipment' ? 'equipment' : 'facilities',
        request()->routeIs('reservations.*') => 'reservations',
        request()->routeIs('official-uses.*') => 'official-uses',
        request()->routeIs('payments.*') => 'payments',
        request()->routeIs('residents.*') => 'residents',
        request()->routeIs('admins.*') => 'admins',
        request()->routeIs('analytics', 'super-admin.analytics') => 'analytics',
        request()->routeIs('profile.*', 'settings.security') => 'profile',
        default => $active,
    };
@endphp

<div class="mobile-drawer-backdrop" aria-hidden="true" hidden></div>
<dialog id="mobile-navigation" class="mobile-drawer" aria-label="eReserve navigation" aria-modal="true">
    <div class="mobile-drawer-heading">
        <strong class="mobile-drawer-title">eReserve</strong>
    </div>
    <div class="mobile-drawer-scroll">
        <div class="mobile-drawer-profile">
            <span class="mobile-drawer-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($user->name), 0, 1)) }}</span>
            <strong class="mobile-drawer-name">{{ $user->name }}</strong>
            <span class="mobile-drawer-email">{{ $user->email }}</span>
            @if (filled($user->barangay))
                <span class="mobile-drawer-barangay">Barangay: {{ $user->barangay }}</span>
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
