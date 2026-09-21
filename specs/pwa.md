# PWA Enhancement for ereserve

# Status: FOR REVIEW

# Overview

This document defines a simple Progressive Web App enhancement for the existing Laravel ereserve project. It is based only on confirmed routes, Blade layouts, assets, colors, and features found in the project.

Confirmed main layout files:

- `resources/views/components/layouts/user.blade.php`
- `resources/views/components/layouts/auth.blade.php`

Confirmed public asset path used by the Blade layouts:

- `/css/app.css`

Confirmed Vite source inputs:

- `resources/css/app.css`
- `resources/js/app.js`

No confirmed linked public JavaScript build output was found in the current Blade layouts.

# Purpose

The purpose of this enhancement is to make ereserve installable on supported browsers, cache basic static assets, and provide a clear offline fallback page when the user loses internet access.

The PWA should support browsing previously loaded facility pages while offline, but reservation submission and other data-changing actions must require an online connection.

# Scope

The PWA enhancement covers:

- Web app manifest setup
- Theme color and browser metadata integration
- Service worker registration in the existing Blade layouts
- Static asset caching for `/css/app.css`
- Offline fallback page and route
- Safe handling for GET navigation requests
- Online-only handling for reservation, facility, profile, authentication, and logout form submissions

# Included

Confirmed existing routes that may be part of the PWA navigation experience:

- `GET /`
- `GET /login`
- `POST /login`
- `GET /register`
- `POST /register`
- `GET /dashboard`
- `GET /facilities`
- `POST /facilities`
- `GET /facilities/{slug}`
- `PATCH /facilities/{slug}`
- `DELETE /facilities/{slug}`
- `POST /facilities/{slug}/reservations`
- `GET /reservations`
- `POST /reservations/{reservation}/accept`
- `POST /reservations/{reservation}/reject`
- `GET /profile`
- `PATCH /profile`
- `PUT /profile/password`
- `POST /logout`

Confirmed implemented features:

- User login
- User registration
- User logout
- Authenticated dashboard
- Facility browsing
- Facility detail viewing
- Facility search and filtering on the facilities page
- Reservation calendar and time slot selection
- Reservation submission for a selected facility and time
- User reservation list
- Admin reservation review
- Admin reservation accept and reject actions
- Admin facility creation
- Admin facility editing
- Admin facility deletion
- Profile information update
- Password update

# Not Included

The PWA enhancement does not include:

- Offline login
- Offline registration
- Offline logout
- Offline reservation submission
- Offline facility creation, editing, or deletion
- Offline reservation approval or rejection
- Background sync for queued reservations
- Push notifications
- API token authentication
- A new API layer
- Caching of unconfirmed public JavaScript build files

# Functional Requirements

1. The application must expose a `manifest.json` file from the public directory.
2. The application must expose a `sw.js` service worker file from the public directory.
3. The authenticated Blade layout must include the manifest link, theme color, and service worker registration.
4. The auth Blade layout may include the same manifest link and theme color for install consistency.
5. The service worker must cache `/css/app.css`, `/offline`, `/favicon.ico`, and the manifest file.
6. Facility browsing pages such as `/facilities` and `/facilities/{slug}` may be available offline after they have been visited online.
7. Reservation submission through `POST /facilities/{slug}/reservations` must not work offline.
8. Admin actions such as facility creation, facility updates, facility deletion, reservation acceptance, and reservation rejection must not work offline.
9. Profile updates and password changes must not work offline.
10. If a navigation request fails while offline and no cached page is available, the user must see the offline page.

# Non-Functional Requirements

1. The enhancement must remain simple enough for a capstone project.
2. The service worker must only cache safe GET requests.
3. The service worker must not cache POST, PATCH, PUT, or DELETE responses.
4. The service worker must not interfere with Laravel CSRF protection.
5. The PWA must use the existing visual identity from `public/css/app.css`.
6. The implementation must not require a new frontend framework.
7. The app must continue to work normally when service workers are unsupported.

# Technical Design

Detected UI colors from `public/css/app.css`:

- Page background: `#f8fafc`
- Auth page background: `#eefafa`
- Topbar color: `#2442ba`
- Primary blue: `#2563eb`
- Action blue: `#155dfc`
- Success green: `#08a83f`
- Danger red: `#dc2626`
- Main text: `#13233d`
- Card background: `#ffffff`

Recommended PWA colors:

- `theme_color`: `#2442ba`
- `background_color`: `#f8fafc`

Recommended public files:

- `public/manifest.json`
- `public/sw.js`

Recommended Blade file updates:

- `resources/views/components/layouts/user.blade.php`
- `resources/views/components/layouts/auth.blade.php`

Recommended offline view:

- `resources/views/offline.blade.php`

# Web App Manifest

Create `public/manifest.json`:

```json
{
  "name": "ereserve",
  "short_name": "ereserve",
  "description": "Barangay Washington asset reservation platform",
  "start_url": "/dashboard",
  "scope": "/",
  "display": "standalone",
  "background_color": "#f8fafc",
  "theme_color": "#2442ba",
  "orientation": "portrait-primary",
  "icons": [
    {
      "src": "/favicon.ico",
      "sizes": "48x48",
      "type": "image/x-icon"
    }
  ]
}
```

# Blade Integration

Add the following inside the `<head>` of `resources/views/components/layouts/user.blade.php` and `resources/views/components/layouts/auth.blade.php`:

```html
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="#2442ba">
```

Add the following before `</body>` in `resources/views/components/layouts/user.blade.php` and `resources/views/components/layouts/auth.blade.php`:

```html
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js');
        });
    }
</script>
```

# Service Worker

Create `public/sw.js`:

```js
const CACHE_NAME = 'ereserve-pwa-v1';
const STATIC_CACHE_URLS = [
    '/offline',
    '/css/app.css',
    '/manifest.json',
    '/favicon.ico'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_CACHE_URLS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((cacheName) => cacheName !== CACHE_NAME)
                    .map((cacheName) => caches.delete(cacheName))
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    return response;
                })
                .catch(() => {
                    return caches.match(request).then((cachedResponse) => {
                        return cachedResponse || caches.match('/offline');
                    });
                })
        );
        return;
    }

    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            return cachedResponse || fetch(request).then((response) => {
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                return response;
            });
        })
    );
});
```

# Service Worker Registration

The service worker registration belongs in the two confirmed layout files:

- `resources/views/components/layouts/user.blade.php`
- `resources/views/components/layouts/auth.blade.php`

The registration must be placed near the end of the body so it does not block page rendering.

# Offline Page

Create `resources/views/offline.blade.php` using the existing auth layout:

```blade
<x-layouts.auth title="Offline - ereserve">
    <section class="auth-card auth-card-login">
        <div class="auth-header">
            <div class="auth-icon auth-icon-blue" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="img">
                    <path d="M12 6v6l4 2" />
                    <circle cx="12" cy="12" r="9" />
                </svg>
            </div>
            <h1>Offline</h1>
            <p>You can view cached pages, but reservations and account changes require an internet connection.</p>
        </div>

        <p class="auth-switch">
            Reconnect to continue using live reservation features.
        </p>
    </section>
</x-layouts.auth>
```

# Laravel Route

Add this route to `routes/web.php`:

```php
Route::view('/offline', 'offline')->name('offline');
```

This route is new and is required for the offline fallback page.

# API Requirements

No new API routes are required.

The current project uses web routes and Blade views for the confirmed reservation and facility workflows. The PWA must work with these existing web routes.

Online-only routes include:

- `POST /login`
- `POST /register`
- `POST /facilities`
- `PATCH /facilities/{slug}`
- `DELETE /facilities/{slug}`
- `POST /facilities/{slug}/reservations`
- `POST /reservations/{reservation}/accept`
- `POST /reservations/{reservation}/reject`
- `PATCH /profile`
- `PUT /profile/password`
- `POST /logout`

# UI Requirements

1. The browser theme color must match the confirmed topbar color `#2442ba`.
2. The manifest background color must match the confirmed user page background `#f8fafc`.
3. The offline page must use existing Blade layout and CSS classes.
4. The offline page must clearly state that reservations and account changes require internet.
5. The PWA must not add a separate landing page.
6. The PWA must preserve the current facility, reservation, dashboard, auth, and profile UI.

# Acceptance Criteria

1. `public/manifest.json` exists and uses `#2442ba` as `theme_color`.
2. `public/manifest.json` uses `#f8fafc` as `background_color`.
3. `public/sw.js` exists.
4. `resources/views/components/layouts/user.blade.php` links to `/manifest.json`.
5. `resources/views/components/layouts/auth.blade.php` links to `/manifest.json`.
6. `resources/views/components/layouts/user.blade.php` registers `/sw.js`.
7. `resources/views/components/layouts/auth.blade.php` registers `/sw.js`.
8. `routes/web.php` includes `GET /offline`.
9. `resources/views/offline.blade.php` exists.
10. `/css/app.css` is cached by the service worker.
11. Previously visited facility browsing pages can be shown while offline.
12. `POST /facilities/{slug}/reservations` is not cached and does not submit while offline.
13. Admin POST, PATCH, and DELETE actions are not cached and do not run offline.
14. Profile update routes are not cached and do not run offline.
15. Failed uncached navigation requests show the offline page.

# Test Cases

1. Open `/login` and verify that the manifest is loaded.
2. Log in and open `/dashboard`.
3. Open `/facilities` while online.
4. Open one facility detail page at `/facilities/{slug}` while online.
5. Open browser developer tools and confirm that `/sw.js` is registered.
6. Confirm that `/css/app.css`, `/manifest.json`, `/favicon.ico`, and `/offline` are stored in cache.
7. Switch the browser to offline mode.
8. Refresh the previously visited `/facilities` page and confirm that it still displays.
9. Refresh the previously visited `/facilities/{slug}` page and confirm that it still displays.
10. Visit an uncached page while offline and confirm that the offline page displays.
11. Try submitting a reservation while offline and confirm that it does not succeed.
12. Return online and confirm that reservation submission works normally.
13. As an admin, confirm that facility management and reservation approval still work online.
14. As an admin, switch offline and confirm that admin form submissions do not succeed.
15. Confirm that the top browser theme color appears as `#2442ba` on supported browsers.

# Future Enhancements

- Add proper PWA icon files in `192x192` and `512x512` sizes.
- Add a custom install prompt.
- Add cache versioning tied to Laravel asset changes.
- Add a user-facing offline banner.
- Add background sync only if the project later supports safe queued actions.
- Add push notifications for reservation status updates.
