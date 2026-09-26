# Account management implementation

Implemented `user_admin_management.md` using the existing Laravel authentication, roles, Create Admin flow, shared layout, barangay list, and reservation/payment model.

## Files created

- `app/Http/Controllers/ResidentController.php`
- `app/Http/Controllers/Concerns/ManagesAccounts.php`
- `app/Http/Middleware/EnsureAccountIsActive.php`
- `database/migrations/2026_09_26_000001_add_is_active_to_users_table.php`
- `resources/views/accounts/index.blade.php`
- `resources/views/accounts/show.blade.php`
- `resources/views/accounts/status-action.blade.php`
- `resources/views/accounts/pagination.blade.php`
- `resources/views/accounts/confirmation.blade.php`
- `public/js/account-management.js`
- `tests/Feature/AccountManagementTest.php`
- `tests/Frontend/account-management.test.cjs`
- `specs/user_admin_management_report.md`

## Files modified

- `app/Http/Controllers/AdminController.php`: extends existing Admin creation with listing, details, and status actions through the shared concern.
- `app/Http/Controllers/AuthController.php`: rejects inactive accounts after credential validation.
- `app/Http/Controllers/TwoFactorLoginController.php`: rejects inactive accounts during pending sign-in.
- `app/Models/User.php`: boolean cast, default active value, and shared deactivation message.
- `app/Services/TwoFactorCodes.php`: rejects inactive users when consuming a security code.
- `bootstrap/app.php`: registers active-account middleware.
- `routes/web.php`: six management routes.
- `resources/views/components/layouts/user.blade.php`: Resident Management for barangay admins, Admin Management for Super Admin, and Super Admin Dashboard label; preserves other navigation items.
- `resources/views/admins/create.blade.php`: adds a link to the management list.
- `tests/Feature/AdminAnalyticsTest.php`: updates navigation expectations for the required new item and role-specific labels; retains Analytics isolation checks.

## Migration

`2026_09_26_000001_add_is_active_to_users_table.php` adds `users.is_active BOOLEAN DEFAULT true`. No existing activation mechanism was present. Existing and newly created accounts default to active. The migration was successfully applied to the configured application database using the specific migration path. Automated tests used only SQLite `:memory:`.

## Routes added

| Method | URL | Name | Controller action |
| --- | --- | --- | --- |
| GET | `/admins` | `admins.index` | `AdminController@index` |
| GET | `/admins/{account}` | `admins.show` | `AdminController@show` |
| PATCH | `/admins/{account}/status` | `admins.status` | `AdminController@updateStatus` |
| GET | `/admin/residents` | `residents.index` | `ResidentController@index` |
| GET | `/admin/residents/{account}` | `residents.show` | `ResidentController@show` |
| PATCH | `/admin/residents/{account}/status` | `residents.status` | `ResidentController@updateStatus` |

Existing `GET /admins/create` and `POST /admins` remain in use.

## Security and data preservation

1. Resident management requires the acting role to be exactly `admin`. Every list/detail/status query requires target role `user` and the authenticated admin's barangay. Search conditions are grouped inside this scope. Reservation history also uses the existing `inBarangayFor` scope.
2. Admin management reuses `authorizeSuperAdmin` and only queries role `admin`. Residents and protected Super Admin accounts, including the actor itself, cannot be targets.
3. Ineligible acting roles receive 403. Out-of-scope target IDs receive 404 from scoped lookups, including PATCH requests. Route parameters are never loaded through an unrestricted user binding.
4. Password login verifies credentials before displaying the deactivation message. Pending two-factor sign-ins and code consumption also check active status. `EnsureAccountIsActive` logs out inactive authenticated accounts, invalidates the session, and redirects to login on their next request.
5. Activate sets `is_active` to true through the same authorized status endpoint. Existing password, email verification, and two-factor requirements remain applicable.
6. Status changes update only `is_active` and the usual model timestamp. Accounts, reservations, recorded payments, notifications, and facility records are not deleted. No deletion route or UI exists.
7. Existing `auth` and `verified` middleware, controller authorization conventions, CSRF protection, Create Admin validation, and email verification are reused. No separate role middleware or policy system was introduced.
8. Existing page-heading, filter-card, profile-card, table, button, modal, and responsive styles are reused without CSS changes. Deactivation requires an explicit dialog confirmation; with JavaScript disabled, deactivation buttons remain disabled.

## Verification

- Full PHPUnit suite: **122 passed, 1,259 assertions** using the unchanged TestCase guard and isolated SQLite `:memory:` configuration.
- New feature coverage: nine tests covering role restrictions, same-barangay visibility, search isolation, direct-ID protection for both status values, details, existing admin creation, activation/deactivation and login for both account types, preservation of reservation/payment/notification history, filters, pagination, active sessions, pending two-factor sign-in, and rejection of identity-field manipulation.
- New frontend coverage: two passing tests for confirmation, correct target submission, duplicate confirmation prevention, Cancel, and Escape-close.
- Laravel Pint completed; JavaScript syntax and `git diff --check` passed.
- Browser visual inspection was not performed.

The user factory, database seeder, Super Admin credentials, analytics calculations, reservation logic, payment calculations, notification behavior, and PWA files were not modified.
