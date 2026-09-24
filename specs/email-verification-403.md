# Verification 403 investigation and fix

## Evidence and cause

The reported link contains user ID **29**. A read-only query confirmed that **user 29 does not exist** in the current development database. Active authenticated database sessions belonged to user IDs **28 and 30** at inspection time. Neither can satisfy the link's user-ID check.

Laravel's `EmailVerificationRequest::authorize()` first compares the authenticated user's key with the link's `{id}`, then compares the SHA-1 of that user's current email with `{hash}`. Either mismatch produces the reported “This action is unauthorized.” response before the controller runs. A missing session instead redirects to login; an invalid signature is rejected by separate signed middleware.

The confirmed current problem is a link targeting a missing account. Its signature cannot make it valid for a replacement/current account. The original failed HTTP request was not recorded with its authenticated identity, so its exact historical `Auth::id()` cannot be recovered or attributed to one of the current sessions. No claim is made about when/why account 29 disappeared. The logs also contained an older link for missing account 23; it was not treated as the reported request.

Session configuration uses the existing database driver, host-only cookies, path `/`, and SameSite `lax`. No custom guard conflict was found. Automated tests confirm that registration's session authenticates subsequent verification requests. There is no evidence that SMTP caused this failure.

## Minimal secure changes

- Added `App\Http\Requests\VerifyEmailRequest`, extending Laravel's `EmailVerificationRequest`. It inherits **unchanged** `authorize()` and `fulfill()` methods and customizes only the failed-authorization response.
- Missing account: a styled HTTP 403 explains that the account no longer exists and directs the user to request a new email for the current account.
- Wrong account: a styled HTTP 403 explains the mismatch and offers logout/account switching. It reveals no recipient email or account details. After switching accounts, reopen the original link.
- Same user with a stale email hash: a styled HTTP 403 asks for a fresh verification link.
- These errors do not change authentication or verify any account. The page provides continuation to the current account's verification notice and the existing CSRF-protected logout action.
- Fixed a separate login-continuation defect: `auth` already stored the signed link as the intended URL, but `AuthController::login()` redirected unverified users to the notice before following it. Login now follows pending same-origin verification URLs first. The destination still runs all verification security checks. Consuming the intended URL prevents a success-page redirect loop back to the link.
- The login page explains which account to use when a verification link is pending. Ordinary login behavior remains unchanged.

## Security and routes

Verification route before and after:

`GET /email/verify/{id}/{hash}` — `web`, `auth`, `signed`, `throttle:6,1`.

No routes or middleware were removed or added for this fix. Authentication, absolute URL signatures, expiration, user-ID comparison, email-hash comparison, resend throttling, `MustVerifyEmail`, `Registered`, and `verified` route protection remain intact. No account is authenticated solely from a link's ID.

## Successful flow and existing UX

- Same browser/correct session: signed notification link verifies that account and shows the existing success page.
- No session/different browser: login preserves and resumes the verification link; authorization is checked against the account actually logged in.
- Wrong session: clear error, no verification; log out, log in to the intended account, and reopen the link.
- Success retains the two-second redirect, spinner, fallback button, and intended destination/shared role-aware dashboard behavior.
- The original tab's server-backed status polling remains unchanged at 2.5 seconds, with success transition and delayed redirect.
- A valid reused link for the correct account still shows “Your email is already verified.” Expired/tampered links remain rejected.

## Files changed for this fix

- `app/Http/Requests/VerifyEmailRequest.php` (new)
- `app/Http/Controllers/EmailVerificationController.php`
- `app/Http/Controllers/AuthController.php`
- `resources/views/auth/verification-link-error.blade.php` (new)
- `resources/views/auth/login.blade.php`
- `tests/Feature/EmailVerificationTest.php`
- `specs/email-verification-403.md` (this report)

No environment files, SMTP settings, schema, PWA, role permissions, or business logic were changed. Development accounts/sessions were inspected read-only and were not altered.

## Validation

`php artisan test`: **55 tests passed, 420 assertions**, using guarded in-memory SQLite.

New coverage reproduces deleted-recipient links, wrong-account login, guest login continuation, expiration after login, registration-session continuity without `actingAs`, original-tab status before/after verification, and usable resend notifications. Existing signature/hash rejection, reused links, success UI, resend throttling, verified middleware, role permissions, barangay scoping, facilities, and reservation regressions pass. Pint and `git diff --check` pass.

These are actual Laravel HTTP/session tests, with notifications captured safely. No live Gmail message was sent or user browser session changed during this fix. The earlier UX work separately exercised two browser tabs with rendered Blade fixtures in headless Chrome.

## Required recovery for link 29

Log in to the current account, open its verification notice, and choose **Resend verification email**. Open the newest email while signed in to that same account. The missing-account link for ID 29 cannot verify a different account and should no longer be used.
