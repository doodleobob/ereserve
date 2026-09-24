# Email-only two-factor authentication

## Existing implementation and removal

The project already had custom session authentication, a protected Security page, email OTP setup/login, five-minute hashed codes, session bindings, attempt/resend limits, and an SMS adapter stub. No real SMS provider had been configured.

Removed the SMS contract, unavailable-provider implementation and container binding, phone masking/casts, phone setup/ownership validation, delivery branches, method-selector styling, phone fields/options/messages, and the Change Method UI. No SMS-specific JavaScript or routes existed. The application now always selects the authenticated user's registered email server-side; submitted method, destination, or user-ID fields cannot redirect delivery or change another account.

## Database safety

Read-only inspection found **zero** users with phone data, zero phone-enabled accounts, and zero phone challenges. Phone fields belonged exclusively to the abandoned SMS feature. There were also zero unverified accounts with 2FA enabled.

Applied `2026_09_24_000002_remove_unused_phone_two_factor_columns.php`, which removes only `users.phone_number` and `users.phone_verified_at`. It refuses to run if phone data, a phone-enabled account, or a phone challenge exists. The before/after comparison confirmed all remaining user data was preserved.

The original applied migration remains intact as migration history. Existing `two_factor_method` storage is retained to avoid rewriting account settings: `null` is disabled and `email` is enabled. The challenge's existing method marker is always `email`; no selectable delivery method remains. No users were deleted, no 2FA settings were reset, and no OTP table was rebuilt.

## Current flows

- **Disabled:** Security shows status, a masked verified email, a short explanation, and Enable Two-Factor Authentication. There are no radio buttons, phone inputs, SMS messages, or method-change controls.
- **Enable:** Confirm the current password and request an email OTP. 2FA remains disabled. The pending state shows the masked destination, code input, Verify and Enable, Resend Code, and Cancel setup. Only a valid, unexpired code enables 2FA and consumes the challenge.
- **Enabled:** Security shows Enabled, the masked email, and a Disable Two-Factor Authentication control. Expanding it reveals a current-password form and Cancel. No setup/method selector is shown.
- **Disable:** The authenticated, verified user's CSRF-protected DELETE request validates their current password, disables their own 2FA, deletes their outstanding challenges, and clears relevant setup/login state. Wrong passwords do not change settings.
- **Normal login:** Disabled accounts authenticate normally without sending an OTP.
- **2FA login:** Correct credentials create only a pending guest state. Email OTP success consumes the code, completes Laravel authentication, regenerates the session, and follows the existing `LoginDestination` behavior. Protected routes remain inaccessible before completion.
- **Verified email:** Setup and code delivery require an already verified email. To prevent an enabled account from being left with an unverified OTP destination, changing its email first requires disabling 2FA through Security. After the ordinary email change and re-verification, the user may enable 2FA again. Name-only changes and email verification for other accounts remain unchanged.

The existing signed-link verification flow, role-aware dashboard/intended destinations, and barangay/admin authorization are preserved. Email verification and email OTP remain separate processes.

## OTP protection

The existing controls remain: secure six-digit generation, five-minute expiry, hashed storage, encrypted challenge destinations, session/purpose/account-state binding, transactional single-use consumption, and invalidation of prior codes on resend. Changing the challenge context means any pre-update pending challenge needs a fresh code/setup attempt; enabled account settings are preserved.

Limits remain five guesses per challenge, ten account-level attempts per five minutes, one send per minute, and five sends per fifteen minutes, with additional route throttles. Pending setup/login states expire after ten minutes. OTPs are excluded from flashed input and are never placed in URLs or browser storage. Log mailers are rejected. Delivery remains synchronous through the existing SMTP configuration; no queue worker is needed.

## Routes and files

Existing settings, enable, confirm, resend, disable, login, and challenge routes remain. Added authenticated/verified `POST /settings/security/two-factor/cancel` (`two-factor.setup.cancel`) so an expired or abandoned setup can be safely discarded and restarted. No SMS routes remain.

Changed or added for this update:

- `app/Services/TwoFactorCodes.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/TwoFactorLoginController.php`
- `app/Http/Controllers/SecurityController.php`
- `app/Http/Controllers/ProfileController.php`
- `bootstrap/app.php`
- `routes/web.php`
- `resources/views/settings/security.blade.php`
- `resources/views/auth/two-factor-challenge.blade.php`
- `resources/views/emails/security-code.blade.php`
- `resources/views/profile.blade.php`
- `public/css/app.css`
- `database/migrations/2026_09_24_000002_remove_unused_phone_two_factor_columns.php`
- `tests/Feature/TwoFactorAuthenticationTest.php`
- `specs/two-factor-implementation.md` (marked historical)
- `specs/two-factor-email-only.md` (this report)

Deleted `app/Contracts/SmsOtpSender.php` and `app/Services/UnavailableSmsOtpSender.php`.

## Validation

`php artisan test`: **77 passed, 686 assertions**, using the existing guard that requires in-memory SQLite before database tests run. Tests did not refresh or alter the development MySQL database.

Coverage includes verified settings access, absent phone/SMS/method controls, disabled login without OTP, setup notifications, wrong/expired setup codes, correct enabling, protected-route bypass prevention for all roles, login success/failure, single use, resend/rate limits, password-confirmed disable, cleared pending state, cross-account request manipulation, actual Laravel email rendering through its test mail transport, and existing email-verification/role/barangay/business regressions.

Pint and `git diff --check` passed. The migration was previewed before application. Runtime application code was searched for remaining SMS/phone references; only historical migrations and removal-regression tests retain such references.

Gmail credentials, environment files, PWA, business logic, and role permissions were untouched. No new live Gmail delivery test was performed or real OTP exposed.
