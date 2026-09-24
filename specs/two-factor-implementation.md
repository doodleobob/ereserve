# Email and phone OTP authentication

> Historical implementation report. The phone feature has since been removed. See [the email-only update](two-factor-email-only.md) for the current implementation.

## Architecture and scope

The installed version is Laravel 13.20.0. Authentication uses the existing custom `AuthController`, Laravel's session guard, and database-backed sessions. No Breeze/Fortify/Jetstream, OTP system, phone field, or SMS provider existed. Gmail SMTP was already the resolved mail transport. The existing Profile Settings page handled name/email/password changes; there was no separate Security page.

Email verification, registration, signed verification links, resend behavior, the success screen, original-tab polling, roles, and barangay authorization remain in place. No environment files, Gmail configuration, PWA code, or unrelated business logic were changed.

## Database

Applied `2026_09_24_000001_add_two_factor_authentication.php` to the existing development database after a SQL preview and isolated tests. It adds:

- Nullable `users.two_factor_method`: `null` means disabled; `email` or `phone` selects exactly one method.
- Nullable encrypted `users.phone_number` and `phone_verified_at`.
- A temporary `two_factor_challenges` table containing user/purpose, method, encrypted destination, code hash, session-binding hash, account-context hash, attempt count, and expiry. A unique user/purpose index limits each account to one active challenge per purpose.

Existing users start with 2FA disabled. A before/after comparison confirmed that all pre-existing user fields were preserved. No development records were reset, deleted, or marked verified.

## Settings and setup

Every role now has **Settings → Security** in the existing navigation. The page uses the current user layout, cards, typography, and buttons, and displays email verification status, 2FA status, masked destinations, method selection, and enable/change/disable controls.

Enabling or changing a method requires the current password, then an OTP delivered to the selected destination. Existing settings stay active until that code is confirmed. Setup authorization lasts 10 minutes. Disabling requires the current password, clears pending challenges, and is authenticated, verified, CSRF-protected, and throttled.

Changing the registered email while Email 2FA is enabled also requires the current password, because this changes a security-code destination. Existing email re-verification still runs afterward.

## Login and redirects

Password validation uses the same Laravel guard/provider but does not authenticate 2FA accounts. A pending state contains only the user ID, a random session binding, an account-state fingerprint, and a 10-minute deadline. No password or OTP is stored in that pending session.

Pending users remain guests. Existing `auth` middleware therefore blocks all application, admin, settings, and email-verification routes until the OTP succeeds. Successful OTP verification consumes the challenge, logs in through Laravel, removes pending state, and regenerates the session. Cancel discards the pending challenge/session.

The original redirect behavior was extracted into `LoginDestination` and is shared by ordinary and OTP logins: resume a pending signed verification link, otherwise send unverified accounts to the notice, otherwise use the intended destination or the existing shared role-aware dashboard. User/admin/super-admin authorization and barangay rules remain unchanged.

## OTP protection and delivery

- Six numeric digits generated with `random_int`; expires after **5 minutes**.
- Stored using Laravel's password hashing service; never stored as plaintext in users/challenges/session or in client storage/URLs.
- Single use, with transactional row locking. Resend replaces the prior challenge and avoids regenerating its numeric value.
- Bound to the requesting session and purpose. Password, email, preferred-method, or trusted-phone changes invalidate outstanding account-context fingerprints.
- Maximum **5 guesses per challenge** and **10 account-level verification attempts per 5 minutes**, including across sessions.
- Sends are limited per account to **1 per minute** and **5 per 15 minutes**, shared across setup/login. Extra route limits apply: login/OTP verification 10 per minute, setup/disable 5 per minute, resend 5 per 15 minutes.
- Codes and phone numbers are excluded from flashed validation input. Forms do not repopulate OTPs. Models hide sensitive fields; phone numbers/destinations are encrypted and masked in views.
- Delivery failures invalidate the newly issued challenge and return a generic error without logging the provider message or code.
- Email uses a synchronous `SecurityCode` notification and the existing SMTP transport. No queue worker is required. Log/fallback transports are rejected for OTP delivery; the array transport is allowed only in tests.
- Expiry is enforced on every verification. An hourly Laravel scheduler task removes expired rows; scheduler availability does not affect expiry enforcement. Run the normal Laravel scheduler in deployed environments for housekeeping.

## Phone support and remaining SMS work

No SMS provider was found. **Real SMS integration was deliberately not implemented.** The Phone option is visibly unavailable, and forged phone-setup requests are rejected server-side. Gmail is not used for SMS.

`SmsOtpSender` defines an adapter interface; the current `UnavailableSmsOtpSender` reports unavailable and cannot send. Once a provider is selected/configured, an adapter can replace this binding in `AppServiceProvider`. It must deliver securely, report availability, throw on failed delivery, and never log codes/full destinations. No provider or credentials were invented.

The prepared ownership flow accepts international numbers beginning with `+`, removes spaces/parentheses/dashes, and validates 8–15 digits with a nonzero country-code prefix. This is syntax validation; successful SMS receipt establishes ownership. A candidate number exists only as an encrypted challenge destination until its code is verified. Successful confirmation stores the encrypted number, sets its verified timestamp, and selects Phone. Replacing a trusted number follows the same ownership check; the old number/method remains active until confirmation. Stored verified numbers remain available when switching to Email or disabling 2FA.

## Routes

| Route | Purpose/protection |
| --- | --- |
| `GET /settings/security` | Authenticated, verified security settings |
| `POST /settings/security/two-factor` | Password-confirmed setup/change |
| `POST /settings/security/two-factor/confirm` | Verify setup OTP |
| `POST /settings/security/two-factor/resend` | Resend setup OTP |
| `DELETE /settings/security/two-factor` | Password-confirmed disable |
| `GET /two-factor-challenge` | Pending guest challenge |
| `POST /two-factor-challenge` | Consume login OTP and authenticate |
| `POST /two-factor-challenge/resend` | Pending guest resend |
| `POST /two-factor-challenge/cancel` | Cancel pending sign-in |

All mutations retain Laravel's web CSRF protection. The existing POST login route gained a rate limit. No additional 2FA middleware is needed because pending users never receive an authenticated session.

## Validation

**74 tests passed, 661 assertions** using the guarded in-memory SQLite test environment. No tests refreshed the development MySQL database.

Coverage includes setup/password/code confirmation, masked/encrypted destinations, wrong password, all-role bypass attempts, existing intended redirects, five-guess lockout, account-level send/guess limits, expiry, resend invalidation, single use, session binding, account changes, disable confirmation, cancellation, failed delivery, log-mailer rejection, validation secrecy, email-verification continuation, and phone ownership through a fake adapter. The actual Laravel mail channel rendered/submitted the OTP notification through the test array transport. Existing email verification and business-feature regression tests pass.

Headless Chrome checked actual rendered Blade fixtures at mobile width for disabled/pending/enabled Settings states and the login challenge. Masking, unavailable SMS selection, empty OTP inputs, and absence of horizontal overflow passed; the challenge screenshot was visually inspected. Temporary fixtures/profile/scripts were removed.

Pint, route registration, and `git diff --check` passed. New live Gmail OTP inbox delivery was not tested in this change; the established SMTP configuration was preserved. No OTPs or credentials were printed or sent as test messages to real accounts.

## Files changed for 2FA

- `database/migrations/2026_09_24_000001_add_two_factor_authentication.php`
- `app/Contracts/SmsOtpSender.php`
- `app/Services/UnavailableSmsOtpSender.php`
- `app/Services/TwoFactorCodes.php`
- `app/Models/TwoFactorChallenge.php`
- `app/Models/User.php`
- `app/Notifications/SecurityCode.php`
- `app/Support/LoginDestination.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/TwoFactorLoginController.php`
- `app/Http/Controllers/SecurityController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `routes/web.php`
- `routes/console.php`
- `resources/views/auth/two-factor-challenge.blade.php`
- `resources/views/settings/security.blade.php`
- `resources/views/emails/security-code.blade.php`
- `resources/views/components/layouts/user.blade.php`
- `resources/views/profile.blade.php`
- `public/css/app.css`
- `tests/Feature/TwoFactorAuthenticationTest.php`
- `specs/two-factor-implementation.md`

To use Email 2FA, open Settings → Security, choose Email, confirm your password and the emailed code, then log out and sign in again. SMS requires selecting and configuring a provider before real phone activation/delivery can be enabled.
