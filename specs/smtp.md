You are working inside my EXISTING Laravel project named `ereserve`.

I want to implement EMAIL VERIFICATION again from scratch.

IMPORTANT:
This is an existing working project.

DO NOT assume a standard Laravel starter-kit structure.
DO NOT blindly follow a generic Laravel tutorial.

FIRST analyze my actual project and make the implementation align
with the architecture, authentication system, database, roles,
routes, controllers, views, and existing functionality that are
already present.

==================================================
VERY IMPORTANT — NO `.env.example`
==================================================

DO NOT use `.env.example`.

DO NOT create `.env.example`.

DO NOT modify `.env.example`.

DO NOT copy configuration into `.env.example`.

Use the existing private `.env` only when runtime configuration
needs to be inspected.

Never commit `.env`.

Never expose or print secrets from `.env`.

In particular, NEVER display:

- MAIL_PASSWORD
- Google App Password
- APP_KEY
- database passwords
- other private credentials

You may determine whether a required setting exists, but report
sensitive values only as:

configured

or:

missing

==================================================
PHASE 1 — ANALYZE MY PROJECT FIRST
==================================================

DO NOT WRITE CODE YET.

Inspect the existing project and determine its actual architecture.

At minimum inspect:

- composer.json
- Laravel version
- routes/web.php
- app/Models/User.php
- authentication controllers
- registration controller/logic
- login controller/logic
- middleware
- bootstrap/app.php
- database migrations related to users
- users table structure
- existing Blade authentication views
- dashboards
- role handling
- barangay handling
- admin authorization
- super-admin authorization
- config/mail.php
- actual private `.env` mail configuration
- queue configuration
- relevant tests

Also search the project for any existing:

- MustVerifyEmail
- email_verified_at
- verified middleware
- verification.notice
- verification.verify
- verification.send
- Registered event
- VerifyEmail notification
- EmailVerificationRequest
- email verification controllers
- email verification Blade pages

Determine whether remnants of the previous implementation still exist.

Do not assume they were completely removed.

==================================================
PHASE 2 — UNDERSTAND EXISTING AUTHENTICATION
==================================================

Determine exactly how authentication currently works.

Identify:

Registration
→ User creation
→ Login
→ Session creation
→ Role detection
→ Dashboard redirect

Determine whether authentication is:

- custom session authentication
- Laravel starter kit
- Breeze
- Fortify
- Jetstream
- another implementation

Use the ACTUAL project as the source of truth.

Do not replace the existing authentication architecture just to
implement email verification.

==================================================
PHASE 3 — UNDERSTAND ROLES
==================================================

Inspect how these roles currently work:

- user
- admin
- super_admin

Preserve the existing role hierarchy and authorization rules.

Determine:

- how normal users are registered
- how admins are created
- how super admins are created
- how each role logs in
- where each role is redirected
- which routes require authentication
- how barangay scoping works

Email verification must integrate with this existing behavior.

Do NOT redesign roles.

==================================================
PHASE 4 — DATABASE ANALYSIS
==================================================

Inspect the existing users migration/database structure.

Determine whether:

email_verified_at

already exists.

If it exists:

DO NOT create another column.

DO NOT create a duplicate migration.

If it does not exist:

create only the minimum migration required.

Do NOT:

- recreate users table
- wipe database
- reset existing users
- delete existing data

NEVER run:

php artisan migrate:fresh

php artisan migrate:refresh

php artisan db:wipe

My existing database and user data must be preserved.

==================================================
PHASE 5 — EMAIL VERIFICATION DESIGN
==================================================

Implement email verification using Laravel's BUILT-IN email
verification system whenever compatible with this project.

Prefer:

Illuminate\Contracts\Auth\MustVerifyEmail

Laravel Registered event

EmailVerificationRequest

Laravel verification notifications

signed verification URLs

verified middleware

Do NOT create a custom verification-token system unless the actual
project architecture makes Laravel's built-in system impossible.

Do NOT create a separate verification_tokens table unnecessarily.

==================================================
PHASE 6 — REGISTRATION FLOW
==================================================

Integrate verification into the EXISTING registration flow.

Desired normal-user flow:

Register
→ validate existing registration fields
→ create user
→ dispatch Laravel Registered event
→ authenticate using existing project behavior
→ redirect unverified user to email verification notice
→ verification email sent
→ user clicks signed verification link
→ email_verified_at is populated
→ user can access protected application routes

Preserve all existing registration requirements, including existing
barangay and role behavior.

Do not weaken existing validation.

==================================================
PHASE 7 — LOGIN FLOW
==================================================

Preserve the existing login system.

Expected behavior:

Verified account
→ login
→ existing role-based destination

Unverified account
→ login is allowed if compatible with current architecture
→ protected application pages remain unavailable
→ redirect to verification notice
→ user can resend verification email

Do not break:

user redirects
admin redirects
super-admin redirects

Use the project's existing redirect logic.

==================================================
PHASE 8 — VERIFICATION ROUTES
==================================================

Add Laravel-compatible verification routes only if they do not
already exist.

Expected functionality:

GET /email/verify

Show verification notice.

GET /email/verify/{id}/{hash}

Verify signed verification request.

POST /email/verification-notification

Resend verification email.

Use:

auth middleware

signed middleware where appropriate

throttling for resend requests

Use the existing Laravel version's recommended APIs.

Do not duplicate existing routes.

==================================================
PHASE 9 — PROTECTED ROUTES
==================================================

Analyze which authenticated pages should require verified email.

Add:

verified

middleware only where appropriate.

Do NOT blindly put verified middleware on every route.

Keep necessary routes accessible for unverified authenticated users,
including:

- verification notice
- verification link
- resend verification email
- logout

Preserve existing authorization middleware and controller checks.

Do not weaken:

- admin authorization
- super-admin authorization
- barangay scoping

==================================================
PHASE 10 — ADMIN-CREATED ACCOUNTS
==================================================

Inspect how admins/users are created by privileged accounts.

If an account is created with a real email address and is expected
to verify it, integrate the existing account-creation flow with
Laravel's verification notification appropriately.

Do NOT assume how this works.

Analyze the actual project first.

Preserve the current super-admin/admin creation rules.

==================================================
PHASE 11 — EXISTING USERS
==================================================

Do NOT automatically mark existing users as verified.

Do NOT modify existing email_verified_at values unnecessarily.

Existing unverified users should be able to:

login
→ reach verification notice
→ request a verification email
→ verify normally

Existing verified users must remain verified.

==================================================
PHASE 12 — ACTUAL `.env`
==================================================

Use my REAL private `.env` for runtime mail configuration.

Again:

DO NOT use `.env.example`.

DO NOT create `.env.example`.

DO NOT modify `.env.example`.

Inspect whether the real runtime configuration resolves to the
correct mail transport.

Expected Gmail SMTP configuration conceptually:

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=<Gmail sender>
MAIL_PASSWORD=<Google App Password>
MAIL_FROM_ADDRESS=<Gmail sender>
MAIL_FROM_NAME="eReserve"

IMPORTANT:

Do NOT output actual username/password credentials unnecessarily.

NEVER output MAIL_PASSWORD.

If MAIL_PASSWORD exists, report:

MAIL_PASSWORD: configured

If missing:

MAIL_PASSWORD: missing

Do not replace credentials yourself.

==================================================
PHASE 13 — VERIFY ACTUAL RUNTIME CONFIG
==================================================

Do not trust `.env` text alone.

Check what Laravel ACTUALLY resolves at runtime.

Safely verify:

config('mail.default')

config('mail.mailers.smtp.host')

config('mail.mailers.smtp.port')

config('mail.mailers.smtp.scheme')

config('mail.from.address')

config('app.url')

NEVER output:

config('mail.mailers.smtp.password')

Expected local APP_URL:

http://127.0.0.1:8000

If configuration cache is stale, safely clear the appropriate
Laravel caches.

==================================================
PHASE 14 — MAIL DELIVERY
==================================================

Before declaring email verification complete, confirm the mail
transport actually works.

First test the SMTP transport independently.

Then test the actual Laravel verification notification.

Distinguish between:

1. Laravel generated the email
2. Laravel submitted it through SMTP
3. Gmail accepted the SMTP message
4. verification notification was sent to the correct recipient

Do not consider an email merely appearing in:

storage/logs/laravel.log

as successful Gmail delivery.

If the complete email HTML appears in laravel.log, determine whether
Laravel is using the log mailer instead of SMTP.

==================================================
PHASE 15 — QUEUE
==================================================

Inspect the project's existing queue configuration.

Determine whether verification notifications are synchronous or
queued.

Do not introduce queued verification emails unnecessarily.

If verification is synchronous, no queue worker should be required
for verification.

Do not change unrelated queue behavior.

==================================================
PHASE 16 — VERIFICATION PAGE
==================================================

Create or update the verification Blade page so that it visually
aligns with the EXISTING eReserve application.

Reuse the project's:

- layout
- typography
- buttons
- spacing
- colors
- navigation patterns

Do not introduce a completely different design framework.

The page should clearly explain that the user must verify their email.

Include:

- resend verification email
- success feedback after resend
- logout option

Keep it responsive.

==================================================
PHASE 17 — TESTING
==================================================

Add/update tests that align with the existing project's testing
architecture.

Test at minimum:

1. registration triggers verification
2. unverified user reaches verification notice
3. verification URL works
4. invalid signature is rejected
5. verified user can access protected routes
6. unverified user cannot access verified-only routes
7. resend verification works
8. resend endpoint is throttled
9. existing verified user behavior still works
10. user role behavior still works
11. admin authorization still works
12. super-admin authorization still works
13. barangay scoping still works

Use safe testing configuration.

DO NOT allow tests to wipe or refresh my real MySQL development
database.

Before running database tests, verify that the test environment is
isolated from my development database.

If it is not safely isolated:

STOP.

Do not run destructive tests against my development database.

==================================================
PHASE 18 — DO NOT BREAK EXISTING FEATURES
==================================================

Email verification must NOT alter the business logic for:

- reservations
- facilities
- equipment
- facility photos
- facility carousel
- calendar
- reservation statuses
- pending/accepted/rejected
- Booked/In Use
- Available/Unavailable
- overlapping reservation requests
- barangay scoping
- user management
- admin management
- super-admin functionality
- PWA functionality

Make the smallest changes necessary.

==================================================
PHASE 19 — DO NOT IMPLEMENT 2FA
==================================================

Do NOT implement two-factor authentication yet.

I want this order:

1. Email verification
2. Test email verification completely
3. Confirm Gmail delivery works
4. Later implement 2FA separately

==================================================
IMPLEMENTATION WORKFLOW
==================================================

Follow this order:

1. Analyze existing project.
2. Identify previous verification remnants.
3. Explain the implementation plan.
4. Verify it aligns with the actual project.
5. Implement minimal changes.
6. Clear stale configuration only if necessary.
7. Run safe tests.
8. Test SMTP.
9. Test real verification notification.
10. Verify existing eReserve functionality remains intact.

==================================================
FINAL REPORT
==================================================

After implementation, report:

1. Laravel version found.
2. Existing authentication architecture found.
3. Existing registration/login flow.
4. How user/admin/super_admin are handled.
5. Whether email_verified_at already existed.
6. Verification architecture implemented.
7. Registration changes.
8. Login changes.
9. Routes added/changed.
10. Middleware added/changed.
11. Controllers added/changed.
12. Models changed.
13. Views added/changed.
14. Whether actual runtime mailer is SMTP.
15. Whether Gmail SMTP authentication succeeded.
16. Whether Gmail accepted a test message.
17. Whether actual verification notification was submitted.
18. Whether verification link successfully verified a user.
19. Test results.
20. Files modified.
21. Any manual steps I still need to perform.

Do NOT include secrets in the report.

==================================================
ABSOLUTE RESTRICTIONS
==================================================

DO NOT:

- use `.env.example`
- create `.env.example`
- modify `.env.example`
- expose `.env`
- print MAIL_PASSWORD
- print Google App Password
- print APP_KEY
- commit `.env`
- hardcode credentials
- create unnecessary custom verification tokens
- implement 2FA
- wipe the database
- recreate the users table
- reset existing users
- remove existing features
- redesign authentication unnecessarily
- change unrelated business logic

The EXISTING eReserve project is the source of truth.

Analyze it first and make email verification fit the project,
not the other way around.