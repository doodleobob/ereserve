<a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'dashboard' ? 'active' : '' }}" href="{{ route('dashboard') }}"@if ($mobile && $active === 'dashboard') aria-current="page" @endif>
    <x-navigation-icon name="home" />
    <span class="nav-label">{{ $user->role === 'super_admin' ? 'Super Admin Dashboard' : ($isAdmin ? 'Admin Dashboard' : 'Dashboard') }}</span>
</a>
@if ($isAdmin)
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'calendar' ? 'active' : '' }}" href="{{ route('calendar') }}"@if ($mobile && $active === 'calendar') aria-current="page" @endif><x-navigation-icon name="calendar" /><span class="nav-label">All Schedules</span></a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'reservations' ? 'active' : '' }}" href="{{ route('reservations.index') }}"@if ($mobile && $active === 'reservations') aria-current="page" @endif>
        <x-navigation-icon name="clipboard" />
        <span class="nav-label">Reservation Management</span>
    </a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'resource-usage' ? 'active' : '' }}" href="{{ route('resource-usage.index') }}"@if ($mobile && $active === 'resource-usage') aria-current="page" @endif><x-navigation-icon name="clipboard" /><span class="nav-label">Resource Usage</span></a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'official-uses' ? 'active' : '' }}" href="{{ route('official-uses.index') }}"@if ($mobile && $active === 'official-uses') aria-current="page" @endif><x-navigation-icon name="shield" /><span class="nav-label">Official Use</span></a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'payments' ? 'active' : '' }}" href="{{ route('payments.index') }}"@if ($mobile && $active === 'payments') aria-current="page" @endif><x-navigation-icon name="payment" /><span class="nav-label">Payments</span></a>
    <details class="resources-navigation {{ in_array($active, ['facilities', 'equipment'], true) ? 'active' : '' }}" data-resources-navigation>
        <summary class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ in_array($active, ['facilities', 'equipment'], true) ? 'active' : '' }}" aria-controls="{{ $mobile ? 'mobile' : 'desktop' }}-resources-links">
            <x-navigation-icon name="grid" />
            <span class="nav-label">Resources</span><span class="resources-chevron" aria-hidden="true">&#8964;</span>
        </summary>
        <div class="resources-navigation-links" id="{{ $mobile ? 'mobile' : 'desktop' }}-resources-links">
            @foreach (['facilities' => 'Facility Management', 'equipment' => 'Equipment Management'] as $destination => $label)
                <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === $destination ? 'active' : '' }}" href="{{ route($destination) }}" @if ($active === $destination) aria-current="page" @endif>
                    <x-navigation-icon :name="$destination === 'facilities' ? 'building' : 'package'" /><span class="nav-label">{{ $label }}</span>
                </a>
            @endforeach
        </div>
    </details>
@else
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'facilities' ? 'active' : '' }}" href="{{ route('facilities') }}"@if ($mobile && $active === 'facilities') aria-current="page" @endif>
        <x-navigation-icon name="building" />
        <span class="nav-label">Facilities</span>
    </a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'equipment' ? 'active' : '' }}" href="{{ route('equipment') }}" @if ($active === 'equipment') aria-current="page" @endif>
        <x-navigation-icon name="package" /><span class="nav-label">Equipment</span>
    </a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'calendar' ? 'active' : '' }}" href="{{ route('calendar') }}" @if ($active === 'calendar') aria-current="page" @endif>
        <x-navigation-icon name="calendar" /><span class="nav-label">All Schedules</span>
    </a>
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'reservations' ? 'active' : '' }}" href="{{ route('reservations.index') }}"@if ($mobile && $active === 'reservations') aria-current="page" @endif>
        <x-navigation-icon name="clipboard" />
        <span class="nav-label">My Reservations</span>
    </a>
@endif
@if ($isAdmin)
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'residents' ? 'active' : '' }}" href="{{ route('residents.index') }}"@if ($mobile && $active === 'residents') aria-current="page" @endif>
        <x-navigation-icon name="users" />
        <span class="nav-label">Resident Management</span>
    </a>
@endif
@if ($user->role === 'super_admin')
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'admins' ? 'active' : '' }}" href="{{ route('admins.index') }}"@if ($mobile && $active === 'admins') aria-current="page" @endif>
        <x-navigation-icon name="admin" />
        <span class="nav-label">Admin Management</span>
    </a>
@endif
@if ($isAdmin)
    <a class="{{ $mobile ? 'drawer-link' : 'nav-link' }} {{ $active === 'analytics' ? 'active' : '' }}" href="{{ route($user->role === 'super_admin' ? 'super-admin.analytics' : 'analytics') }}"@if ($mobile && $active === 'analytics') aria-current="page" @endif>
        <x-navigation-icon name="chart" />
        <span class="nav-label">Analytics</span>
    </a>
@endif
