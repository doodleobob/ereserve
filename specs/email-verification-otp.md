# Email verification uses codes only

## Inspection and cause

The inspected checkout had two email authentication workflows, but only one registration verification workflow: Laravel's clickable link. The existing OTP implementation was for two-factor setup and login, and explicitly required an already verified email address. No separate registration OTP implementation or duplicate registration listener was found in application providers, routes, models, controllers, notifications, migrations, views, or tests. The available Git history does not establish a particular merge as the source of duplicate messages.

Registration and Admin creation each fired one `Registered` event. Laravel's standard listener called the inherited `User::sendEmailVerificationNotification()`, which sent `Illuminate\Auth\Notifications\VerifyEmail`. Resend and Profile email changes also called that inherited method. Meanwhile, the independent `TwoFactorCodes` service sent code emails for login/setup. The integration problem was that registration still used the default link sender instead of the existing code service.

## Old link implementation

- `routes/web.php`: signed `GET /email/verify/{id}/{hash}` and status polling endpoint.
- `app/Http/Controllers/EmailVerificationController.php`: signed-request fulfillment, link resend, success view, polling.
- `app/Http/Requests/VerifyEmailRequest.php`: signed-link ownership/error handling; removed.
- `resources/views/auth/verification-link-error.blade.php`, `verification-success.blade.php`, and `partials/verification-success.blade.php`: link-only views; removed.
- `public/js/email-verification.js`: link-tab polling and delayed redirection; removed.
- `resources/views/auth/verify-email.blade.php`: link instructions; converted to the only email verification code form.
- `app/Support/LoginDestination.php` and `resources/views/auth/login.blade.php`: link continuation behavior; removed, with stale intended URLs discarded.
- Laravel's default link notification remains in vendor code but is no longer sent by the application.

## Reused OTP implementation

- `app/Services/TwoFactorCodes.php`: generation, hashed storage, encrypted destination, account/context binding, throttling, expiry, attempt tracking, and atomic consumption. Added the `verify` purpose to this existing service.
- `app/Models/TwoFactorChallenge.php` and `database/migrations/2026_09_24_000001_add_two_factor_authentication.php`: reused without changes. No new table or migration.
- `app/Notifications/SecurityCode.php` and `resources/views/emails/security-code.blade.php`: reused with verification-specific subject/instructions.
- Two-factor login/setup controllers and their session-bound challenges remain in place.

`User` still implements `MustVerifyEmail`. The existing `verified` middleware and `email_verified_at` remain the authority for access. Its notification method now delegates to the existing code service, so registration has one event, one listener, and one code email.

## Modified files

- `app/Models/User.php`
- `app/Services/TwoFactorCodes.php`
- `app/Notifications/SecurityCode.php`
- `app/Http/Controllers/EmailVerificationController.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Support/LoginDestination.php`
- `routes/web.php`
- `resources/views/auth/verify-email.blade.php`
- `resources/views/auth/login.blade.php`
- `resources/views/emails/security-code.blade.php`
- `public/sw.js` (exclude email pages from offline caching and invalidate old cached link pages)
- `tests/Feature/EmailVerificationTest.php`
- `tests/Feature/TwoFactorAuthenticationTest.php` (only obsolete link-continuation test)
- `tests/Frontend/admin-analytics-cache.cjs`

Deleted files are listed in the old implementation section above. This report is new.

## Final flow and routes

Register → create and authenticate an unverified account → fire one `Registered` event → send one six-digit code → `GET /email/verify` → submit code to `POST /email/verify` → atomically mark email verified and delete the challenge → fire `Verified` once → regenerate session → continue to the intended page or dashboard.

`POST /email/verification-notification` replaces the outstanding code, subject to existing send limits. Codes expire after five minutes; five wrong guesses invalidate a challenge. Codes belong to the authenticated account and its current email/security context. They are not flashed into session input, returned in page data, or sent through a log mailer. Old signed URLs and the polling endpoint return 404.

## Validation

Automated tests cover one real rendered code email per registration, no clickable link, incorrect/correct/expired codes, replay prevention, cross-account submission, changed-email invalidation, resend replacement/throttling, guest/unverified restrictions, role permissions, intended destinations, delivery failure recovery, and existing two-factor behavior. Verification uses the existing schema, so no database migration is required.

- `php artisan test --compact`: 148 tests passed, 1,689 assertions, using isolated in-memory SQLite and the test mail transport.
- `node tests/Frontend/admin-analytics-cache.cjs`: 32 cache checks passed, including current and obsolete verification URLs.
- `git diff --check`: passed.

No real verification emails were sent to external recipients during testing. Earlier link-verification reports describe historical behavior and are superseded by this report.
