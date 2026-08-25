@props(['title' => 'eReserve', 'active' => 'dashboard'])

@php
    $user = auth()->user();
    $displayRole = $user->role === 'admin' ? 'Administrator' : 'Resident';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="user-page">
    <header class="user-topbar">
        <div class="page-shell topbar-inner">
            <div class="brand-block">
                <h1>eReserve</h1>
                <p>Barangay Washington Asset Reservation Platform</p>
            </div>

            <div class="user-tools">
                <div class="user-summary">
                    <strong>{{ $user->name }}</strong>
                    <span>{{ $displayRole }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-button">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                            <path d="M10 17l5-5-5-5" />
                            <path d="M15 12H3" />
                        </svg>
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </header>

    <nav class="user-nav">
        <div class="page-shell nav-inner">
            <a class="nav-link {{ $active === 'dashboard' ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 10.5 12 3l9 7.5" />
                    <path d="M5 10v10h14V10" />
                    <path d="M9 20v-6h6v6" />
                </svg>
                Dashboard
            </a>
            <a class="nav-link {{ $active === 'facilities' ? 'active' : '' }}" href="{{ route('facilities') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                    <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                    <path d="M10 6h4M10 10h4M10 14h4" />
                </svg>
                Facilities
            </a>
            <a class="nav-link {{ $active === 'reservations' ? 'active' : '' }}" href="{{ route('reservations.index') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M8 2v4M16 2v4M3 10h18" />
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                </svg>
                My Reservations
            </a>
            <a class="nav-link {{ $active === 'profile' ? 'active' : '' }}" href="{{ route('profile.edit') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M20 21a8 8 0 0 0-16 0" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                Profile
            </a>
        </div>
    </nav>

    <main class="page-shell user-main">
        {{ $slot }}
    </main>

    <footer class="user-footer">
        <p><strong>&copy; 2026 Barangay Washington. All rights reserved.</strong></p>
        <p>eReserve - Asset Reservation and Utilization Platform</p>
    </footer>
</body>
</html>
