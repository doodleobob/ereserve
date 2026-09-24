@props(['title' => 'eReserve', 'active' => 'dashboard'])

@php
    $user = auth()->user();
    $isAdmin = in_array($user->role, ['admin', 'super_admin'], true);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#2442ba">
    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="user-page">
    <header class="user-topbar">
        <div class="page-shell topbar-inner">
            <div class="brand-block">
                <h1>eReserve</h1>
                <p>Barangay Asset Reservation Platform</p>
            </div>

            <div class="user-tools">
                <div class="user-summary">
                    <strong>{{ $user->name }}</strong>
                    <span>Role: {{ $user->role }}</span>
                    <span>Barangay: {{ $user->barangay }}</span>
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
                {{ $isAdmin ? 'Admin Dashboard' : 'Dashboard' }}
            </a>
            @if ($isAdmin)
                <a class="nav-link {{ $active === 'reservations' ? 'active' : '' }}" href="{{ route('reservations.index') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M8 2v4M16 2v4M3 10h18" />
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                    </svg>
                    Reservation Management
                </a>
                <a class="nav-link {{ $active === 'facilities' ? 'active' : '' }}" href="{{ route('facilities') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M2 12h3M19 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12" />
                    </svg>
                    Facility Management
                </a>
                @if ($user->role === 'super_admin')
                    <a class="nav-link {{ $active === 'admins' ? 'active' : '' }}" href="{{ route('admins.create') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <path d="M19 8v6" />
                            <path d="M22 11h-6" />
                        </svg>
                        Admins
                    </a>
                @endif
            @else
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
            @endif
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
        <p><strong>&copy; 2026 eReserve. All rights reserved.</strong></p>
        <p>eReserve - Asset Reservation and Utilization Platform</p>
    </footer>
    <script>
        document.querySelectorAll('[data-facility-carousel]').forEach((carousel) => {
            const slides = Array.from(carousel.querySelectorAll('[data-carousel-slide]'));
            const indicators = Array.from(carousel.querySelectorAll('[data-carousel-indicator]'));
            let currentIndex = 0;

            const showSlide = (index) => {
                currentIndex = (index + slides.length) % slides.length;

                slides.forEach((slide, slideIndex) => {
                    slide.hidden = slideIndex !== currentIndex;
                });
                indicators.forEach((indicator, indicatorIndex) => {
                    const active = indicatorIndex === currentIndex;
                    indicator.classList.toggle('active', active);
                    indicator.setAttribute('aria-current', active ? 'true' : 'false');
                });
            };

            carousel.querySelector('[data-carousel-previous]')?.addEventListener('click', () => {
                showSlide(currentIndex - 1);
            });
            carousel.querySelector('[data-carousel-next]')?.addEventListener('click', () => {
                showSlide(currentIndex + 1);
            });
            indicators.forEach((indicator) => {
                indicator.addEventListener('click', () => showSlide(Number(indicator.dataset.carouselIndicator)));
            });
            carousel.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') {
                    showSlide(currentIndex - 1);
                } else if (event.key === 'ArrowRight') {
                    showSlide(currentIndex + 1);
                }
            });
        });

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js');
            });
        }
    </script>
</body>
</html>
