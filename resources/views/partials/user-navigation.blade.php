<a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'dashboard' ? 'active' : '' }}" href="{{ route('dashboard') }}"@if ($mobile && $active === 'dashboard') aria-current="page" @endif>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M3 10.5 12 3l9 7.5" />
        <path d="M5 10v10h14V10" />
        <path d="M9 20v-6h6v6" />
    </svg>
    <span class="nav-label">{{ $user->role === 'super_admin' ? 'Super Admin Dashboard' : ($isAdmin ? 'Admin Dashboard' : 'Dashboard') }}</span>
</a>
@if ($isAdmin)
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'calendar' ? 'active' : '' }}" href="{{ route('calendar') }}"@if ($mobile && $active === 'calendar') aria-current="page" @endif>@if ($mobile)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18" /><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M8 14h2M14 14h2M8 18h2" /></svg>@endif<span class="nav-label">Calendar</span></a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'reservations' ? 'active' : '' }}" href="{{ route('reservations.index') }}"@if ($mobile && $active === 'reservations') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M8 2v4M16 2v4M3 10h18" />
            <rect x="3" y="4" width="18" height="18" rx="2" />
        </svg>
        <span class="nav-label">Reservation Management</span>
    </a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'official-uses' ? 'active' : '' }}" href="{{ route('official-uses.index') }}"@if ($mobile && $active === 'official-uses') aria-current="page" @endif>@if ($mobile)<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="14" rx="2" /><path d="M8 7V3h8v4M3 12h18M10 12v3h4v-3" /></svg>@endif<span class="nav-label">Official Use</span></a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'payments' ? 'active' : '' }}" href="{{ route('payments.index') }}"@if ($mobile && $active === 'payments') aria-current="page" @endif>@if ($mobile)<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2" /><path d="M2 9h20M6 15h4" /></svg>@endif<span class="nav-label">Payments</span></a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'facilities' ? 'active' : '' }}" href="{{ route('facilities') }}"@if ($mobile && $active === 'facilities') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="3" />
            <path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M2 12h3M19 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12" />
        </svg>
        <span class="nav-label">Facility Management</span>
    </a>
@else
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'facilities' ? 'active' : '' }}" href="{{ route('facilities') }}"@if ($mobile && $active === 'facilities') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
            <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
            <path d="M10 6h4M10 10h4M10 14h4" />
        </svg>
        <span class="nav-label">Facilities</span>
    </a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'reservations' ? 'active' : '' }}" href="{{ route('reservations.index') }}"@if ($mobile && $active === 'reservations') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M8 2v4M16 2v4M3 10h18" />
            <rect x="3" y="4" width="18" height="18" rx="2" />
        </svg>
        <span class="nav-label">My Reservations</span>
    </a>
@endif
@if ($isAdmin)
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'residents' ? 'active' : '' }}" href="{{ route('residents.index') }}"@if ($mobile && $active === 'residents') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0" /><circle cx="12" cy="7" r="4" /></svg>
        <span class="nav-label">Resident Management</span>
    </a>
@endif
@if ($user->role === 'super_admin')
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'admins' ? 'active' : '' }}" href="{{ route('admins.index') }}"@if ($mobile && $active === 'admins') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M19 8v6M22 11h-6" />
        </svg>
        <span class="nav-label">Admin Management</span>
    </a>
@endif
@if ($isAdmin)
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'analytics' ? 'active' : '' }}" href="{{ route($user->role === 'super_admin' ? 'super-admin.analytics' : 'analytics') }}"@if ($mobile && $active === 'analytics') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 3v18h17M8 17v-5M13 17V8M18 17V4" />
        </svg>
        <span class="nav-label">Analytics</span>
    </a>
@endif
<a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'profile' ? 'active' : '' }}" href="{{ route('profile.edit') }}"@if ($mobile && $active === 'profile') aria-current="page" @endif>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M20 21a8 8 0 0 0-16 0" />
        <circle cx="12" cy="7" r="4" />
    </svg>
    <span class="nav-label">Profile</span>
</a>
