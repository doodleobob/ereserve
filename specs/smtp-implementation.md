# Email verification implementation

## Architecture found

- Installed Laravel version: 13.20.0 (Composer constraint ^13.8).
- Custom `AuthController` with Laravel's session guard; no Breeze, Fortify, or Jetstream.
- Registration validates name, unique email, confirmed password (minimum eight characters), and an allowed barangay. It creates a `user`, authenticates, and regenerates the session.
- Login uses `Auth::attempt`, regenerates the session, and redirects to the intended destination or shared dashboard. Dashboard/controller logic determines role-specific behavior.
- Public registration always assigns `user`. Only `super_admin` can create `admin` accounts. The existing seeder creates demo user/admin/super-admin accounts; it was not changed or run.
- Admin permissions and barangay scoping remain in existing controllers/support classes. Super admins retain existing cross-barangay access.
- `email_verified_at` already exists in both the original migration and the development database. Existing remnants were the commented verification contract, timestamp cast, and factory verified/unverified states. No active verification routes/controllers/views were present.

## Changes

- `User` implements Laravel's `MustVerifyEmail` contract using inherited built-in methods.
- Registration dispatches `Registered` after establishing the session, then redirects to the verification notice. Laravel's existing event listener sends the standard synchronous `VerifyEmail` notification.
- Unverified accounts of every role can log in, but are directed to the notice. Verified accounts retain their existing redirect behavior.
- Super-admin-created admins receive a verification notification without replacing the creator's session.
- Changing a profile email clears that account's verification timestamp and sends a new notification. Name-only changes preserve verification. Old-email links no longer authorize verification.
- Transport failures show recovery feedback without deleting the newly created account or printing SMTP diagnostics/secrets.
- Added authenticated routes:
  - `GET /email/verify` (`verification.notice`).
  - `GET /email/verify/{id}/{hash}` (`verification.verify`), signed and throttled to six requests per minute.
  - `POST /email/verification-notification` (`verification.send`), throttled to six requests per minute.
- Existing application routes now use `auth` and Laravel's built-in `verified` middleware. Verification routes and logout remain available to unverified accounts. Public authentication/offline routes remain accessible.
- Verification uses `EmailVerificationRequest::fulfill()`, signed expiring URLs, the standard notification, and the existing timestamp. No custom tokens, migrations, middleware classes, queues, or 2FA were introduced.
- The notice reuses the existing auth layout, CSS typography/colors/buttons, and responsive card, with resend feedback and logout.

## Runtime and delivery checks

- Both private environment configuration and resolved runtime configuration use SMTP, `smtp.gmail.com`, port `587`, scheme `smtp`.
- Username and sender address: configured. `MAIL_PASSWORD`: configured. Credentials were neither changed nor printed.
- Runtime `APP_URL`: `http://127.0.0.1:8000`.
- Queue connection remains `database`; built-in verification notifications are synchronous and need no queue worker.
- Configuration cache was cleared so PHPUnit could use its isolated test settings. A stale route cache was discovered during the notification check and cleared to expose the new routes.
- Gmail SMTP authentication succeeded outside the Windows sandbox.
- Gmail accepted the independent SMTP test message.
- Gmail accepted the real Laravel `VerifyEmail` notification; the `MessageSent` event confirmed the requested recipient, `ereservesystem@gmail.com`.
- SMTP acceptance does not confirm inbox placement or that the message was read.
- No development account matched the requested recipient. The delivery check therefore used an unsaved test identity with ID 0. Its emailed link cannot verify a development account. No existing account or verification timestamp was changed by this check.
- The actual notification-generated signed URL successfully populated the verification timestamp in an isolated automated test, and repeat use preserved that timestamp. Real-account browser verification remains a manual check.

## Validation

`php artisan test`: **47 tests passed, 330 assertions**.

The shared test bootstrap now refuses to start database tests unless the environment is `testing`, the default connection is SQLite, its database is `:memory:`, and no SQLite connection URL overrides it. This guard runs before `RefreshDatabase` setup. The development MySQL database was not refreshed or wiped.

Coverage includes registration notifications, authentication, all three roles, notice access, protected routes, notification-generated links, invalid/expired signatures, another user's link, idempotence, resend and throttling, admin creation, email changes, transport failure recovery, and guest access. Existing barangay authorization, calendar/reservation, and facility/photo tests also pass.

## Files modified or added

- `app/Models/User.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/EmailVerificationController.php` (new)
- `routes/web.php`
- `resources/views/auth/verify-email.blade.php` (new)
- `tests/TestCase.php`
- `tests/Feature/SaasBarangayTest.php`
- `tests/Feature/EmailVerificationTest.php` (new)
- `specs/smtp-implementation.md` (this report)

No environment file, seeder, migration, role/business logic, or PWA file was modified. The temporary SMTP check script was removed after use.

## Manual check

1. Run the app at `http://127.0.0.1:8000` and register an account using an inbox you control, such as the requested test recipient.
2. Open the newly generated account verification email in the same browser where you are logged in. Use the fresh registration email, not the ID-0 delivery-test notification.
3. Confirm dashboard access after verification; check spam if necessary. Local links must be opened on the computer running the app.
4. Existing unverified accounts can log in and resend verification. Demo accounts with `example.com` addresses cannot receive real mail; provision real addresses through your normal account-management process. Existing users were not automatically marked verified.
