# Email verification UX update

## Existing behavior

`EmailVerificationController::verify()` called Laravel's `EmailVerificationRequest::fulfill()` and immediately redirected using `redirect()->intended(route('dashboard'))`. The original notice had no way to detect verification in another tab.

The project uses a shared dashboard whose controller and Blade render role-specific content. Login and verification both use the intended destination with that dashboard as their fallback; there is no separate role redirect service.

## New behavior

- The signed verification route still calls `fulfill()` with unchanged authentication, signature validation, and throttling. It now renders a success page with a checkmark, “Email verified successfully!”, confirmation text, a lightweight spinner, and “Redirecting you to eReserve...”.
- JavaScript redirects after 2 seconds using `location.replace()`. A “Continue to eReserve” link works without JavaScript and provides a manual fallback.
- A valid, already-used link displays “Your email is already verified.” and redirects normally. Invalid and expired signatures remain rejected.
- Both tabs preserve the existing intended destination/dashboard behavior. The original tab captures its destination when its notice renders; the signed-link tab uses Laravel's existing intended redirect resolver. User/admin/super-admin authorization and dashboard rendering remain unchanged.
- The original notice polls the authenticated server every 2.5 seconds after each completed response, then swaps its waiting content for the same success component and redirects after 2 seconds.
- Polling stops once verified, avoids overlapping requests/timers, pauses while hidden, and checks immediately when the tab becomes visible. Network errors retry silently; requests time out after 8 seconds. Login redirects and expired/unauthorized sessions stop polling. Rate-limited responses back off to 10 seconds. Page navigation stops timers; browser back/forward restoration resumes appropriately.

## Routes and server state

Added `POST /email/verification-status` (`verification.status`) under the existing `auth` middleware. It remains accessible to unverified accounts, uses existing web CSRF protection, reloads the authenticated user's model, and returns only `{"verified": true}` or `{"verified": false}` with `Cache-Control: no-store, private`.

POST is deliberate: the existing service worker caches GET responses. Read-only POST checks bypass that cache without changing PWA functionality. No client-side storage is treated as verification evidence.

The existing signed `GET /email/verify/{id}/{hash}` now renders success instead of immediately redirecting. Other verification routes and resend behavior are unchanged.

## Files changed for this UX update

- `app/Http/Controllers/EmailVerificationController.php`: status action, success rendering, intended destination for the original tab.
- `routes/web.php`: authenticated status route.
- `resources/views/auth/verify-email.blade.php`: waiting/success containers and polling configuration.
- `resources/views/auth/verification-success.blade.php`: new signed-link success page.
- `resources/views/auth/partials/verification-success.blade.php`: shared success UI and fallback link.
- `public/js/email-verification.js`: polling, lifecycle handling, state transition, and delayed redirect.
- `public/css/app.css`: scoped verification styles and spinner, respecting reduced-motion preferences.
- `tests/Feature/EmailVerificationTest.php`: success/already-verified assertions, fresh minimal status responses, guest protection, and intended destinations for all roles.
- `specs/email-verification-ux.md`: this report.

## Validation

- `php artisan test`: **49 passed, 365 assertions**, with the existing safeguard requiring isolated in-memory SQLite. Registration notification, signed-link verification, resend/throttling, unverified access restrictions, roles, and existing business-feature regressions pass.
- Headless Chrome exercised actual rendered Blade pages against a temporary local fixture server: two-tab detection, success state, automatic redirects in both tabs, stopped polling, reused-link messaging, 390px mobile layout, network failure/recovery, and manual continuation all passed.
- Browser status/verification responses were simulated; Laravel feature tests covered the actual backend separately. A new live Gmail registration/inbox/browser test was not performed for this UX-only change. SMTP delivery was established in the earlier implementation and reported working by the user.
- Route listing confirms the new route; `git diff --check` passes. Temporary browser fixtures/scripts/profile were removed.

The verification backend, model contract, events, notifications, timestamps, resend security, SMTP settings, environment files, database structure, authentication session, role rules, business logic, and PWA code were preserved.
