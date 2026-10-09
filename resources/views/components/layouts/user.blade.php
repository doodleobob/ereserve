@props(['title' => 'eReserve', 'active' => 'dashboard'])

@php
    $user = auth()->user();
    $isAdmin = in_array($user->role, ['admin', 'super_admin'], true);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#2442ba">
    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('styles')
    <script src="{{ asset('js/modals.js') }}?v={{ filemtime(public_path('js/modals.js')) }}" defer></script>
</head>
<body class="user-page">
    <header class="user-topbar">
        <div class="page-shell topbar-inner">
            <button type="button" class="mobile-menu-button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="mobile-navigation" aria-haspopup="dialog" hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>
            <div class="brand-block">
                <h1>eReserve</h1>
                <p>Barangay Asset Reservation Platform</p>
            </div>

            <div class="user-tools">
                <div class="user-summary">
                    <strong>{{ $user->name }}</strong>
                    @if ($user->role !== 'super_admin')
                        <span>Barangay: {{ $user->barangay }}</span>
                    @endif
                </div>
                @include('partials.notifications')
                @include('partials.logout')
            </div>
        </div>
    </header>

    <nav class="user-nav">
        <div class="page-shell nav-inner {{ $user->role === 'super_admin' ? 'nav-inner-super-admin' : '' }}">
            @include('partials.user-navigation', ['mobile' => false])
        </div>
    </nav>

    @include('partials.mobile-navigation')

    <main class="page-shell user-main" data-modal-page="{{ $active }}">
        {{ $slot }}
    </main>

    <script src="{{ asset('js/mobile-navigation.js') }}?v={{ filemtime(public_path('js/mobile-navigation.js')) }}" defer></script>
    @stack('scripts')
    <x-footer />
    <script src="{{ asset('js/notifications.js') }}?v={{ filemtime(public_path('js/notifications.js')) }}" defer></script>
    <script src="{{ asset('js/facility-gallery.js') }}?v={{ filemtime(public_path('js/facility-gallery.js')) }}" defer></script>
    @include('partials.pwa-registration')
</body>
</html>
