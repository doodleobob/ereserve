# Profile Security implementation

Two-factor authentication management now lives in the existing Profile page. Personal Information, Change Password, and Account Information remain in place. The new Security card reuses the existing profile layout and styles, displays verification and enabled status, and uses the authenticated user's masked email.

## Navigation and routes

- Removed the standalone Settings navigation item. Profile is available to users, admins, and super admins; existing role-specific navigation remains.
- `/settings/security` redirects to `/profile` behind the existing authentication and verified-email middleware.
- Removed the standalone Settings Blade and its controller rendering action. Existing 2FA action URLs and route names remain available; successful management actions return to `/profile#security`.

## Flows and security

- Enable: expand the confirmation panel, submit the current password, receive an email OTP, and confirm the code. Success returns to Profile with an enabled message.
- Disable: expand the confirmation panel and submit the current password. Existing server-side validation, challenge deletion, and pending-state cleanup remain. Success returns to Profile with a disabled message.
- The new 2FA password inputs are initially collapsed. Cancel clears and closes the confirmation panel. Password validation errors reopen it. Security errors use a separate validation bag to avoid appearing in the existing Change Password card.
- Existing email OTP generation, delivery, expiration, single use, rate limits, account/session binding, login challenge, session regeneration, verified-email requirements, and authorization are preserved. The login controller, OTP service, and notification were not changed for this move.
- Profile is the single management UI. Remaining `/settings/security/two-factor` endpoints are working backend actions, not a second settings page.

## Files changed for this request

1. `app/Http/Controllers/ProfileController.php`: supplies setup state and security errors, prevents caching the management page, and updates the email-change guidance.
2. `app/Http/Controllers/SecurityController.php`: removes the old rendering action, returns management actions to Profile, and separates security validation errors.
3. `resources/views/profile.blade.php`: includes the Security card and local links.
4. `resources/views/partials/profile-security.blade.php`: new email-only management card and confirmation forms.
5. `resources/views/components/layouts/user.blade.php`: removes Settings and exposes Profile for all existing roles.
6. `resources/views/settings/security.blade.php`: removed standalone view.
7. `public/css/app.css`: removes the unused standalone settings container rule.
8. `routes/web.php`: redirects the old settings page.
9. `tests/Feature/ProfileSecurityTest.php`: new navigation, Profile preservation, confirmation UI, validation, redirect, and access tests.
10. `tests/Feature/TwoFactorAuthenticationTest.php`: updates management-page locations, redirects, and security error-bag assertions.
11. `specs/profile-security-implementation.md`: this report.

Other pre-existing uncommitted SMTP, verification, and 2FA implementation files were preserved. No environment configuration, database migrations, or development database records were changed for this request. No live email was sent during verification.

## Validation

`php artisan test`: **83 tests passed, 759 assertions**. Targeted Pint checks and `git diff --check` passed. The obsolete-reference search found no remaining standalone Settings UI links.

Feature tests use in-memory SQLite and fake notifications; the test harness rejects development database connections. Coverage includes enable/confirm/disable, password rejection, OTP-protected and ordinary login, bypass attempts, role destinations, and barangay scoping. Profile tests also verify the collapsed confirmation markup and error reopening using browser-like session-cookie continuity. No interactive browser or live SMTP test was performed for this UI relocation.
