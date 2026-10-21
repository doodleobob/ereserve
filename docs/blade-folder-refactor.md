# Blade folder refactor audit

Structural refactor recorded on 2026-10-21 (Asia/Manila). All paths below are relative to the repository root unless stated otherwise.

## Audit and organization decisions

- Inventoried all 73 Blade files before edits; no byte-identical duplicates existed. The working tree was clean.
- Traced view calls, response views, Blade includes, anonymous components, direct test file reads, and view assertions across application code, routes, middleware, providers, configuration, JavaScript, and tests. No custom Blade aliases, view namespaces, or dynamic runtime view construction requiring relocation were found.
- Resident is stored as `user` in the existing application. Only the folder name is `resident`; no role values or permissions changed.
- Facilities and equipment deliberately share the same feature templates. Their listing and detail pages serve all roles. Even the reservation form can render through the existing detail-page conditions for administrators; it remains shared. No separate equipment folders or duplicated pages were introduced.
- Reservations use one full page with role branches. The resident card is exclusive and moves to `resident/reservations/partials/`; the admin table/dialogs also serve super_admin and stay shared. Conflict details serve all roles.
- Operations dashboards, account/resident oversight, Official Use, and payment management/reporting serve both administrative roles and move to `shared/`.
- Admin analytics redirects super_admin to its separate analytics controller. Only these genuinely exclusive analytics views move under their respective role folders.
- Admin creation and its form fields are super_admin-only. The existing `admins/` feature name is preserved under `super_admin/`; the shared accounts page still includes those fields only in its existing admin-management branch.
- Profile/security and calendars serve all roles. Partials previously mixed with full pages move into feature-specific `partials/` folders.
- Components and their `<x-...>` aliases, component layouts, auth pages, emails, and global navigation/PWA/validation partials remain in place. There was no standalone `layouts/` directory to move or create.
- `welcome.blade.php`, `components/management-table.blade.php`, and `components/table-entries.blade.php` have no discovered callers; they are retained, not guessed at or deleted.
- Dynamic references in test fixtures are `view('auth.'.$page, ...)` and `resources/views/components/layouts/${layout}.blade.php`. Both target unchanged directories. The frontend JavaScript uses routes, DOM selectors, and assets rather than relocated view names.
- View names such as `reservations.index`, `payments.index`, `official-uses.index`, `facilities.show`, `admins.create`, and `profile.edit` also occur as route names. Route calls and route declarations are preserved.

## Original structure

```text
resources/views/
├── accounts/
│   ├── confirmation.blade.php
│   ├── details.blade.php
│   ├── index.blade.php
│   ├── pagination.blade.php
│   ├── show.blade.php
│   └── status-action.blade.php
├── admins/
│   ├── create.blade.php
│   └── form-fields.blade.php
├── analytics/
│   ├── partials/
│   │   └── admin-analytics.blade.php
│   ├── index.blade.php
│   └── super-admin.blade.php
├── auth/
│   ├── forgot-password.blade.php
│   ├── login.blade.php
│   ├── register.blade.php
│   ├── reset-password.blade.php
│   ├── two-factor-challenge.blade.php
│   └── verify-email.blade.php
├── calendar/
│   ├── availability.blade.php
│   ├── equipment-schedule.blade.php
│   ├── filters.blade.php
│   ├── index.blade.php
│   ├── quantity.blade.php
│   └── schedules.blade.php
├── components/
│   ├── layouts/
│   │   ├── auth.blade.php
│   │   └── user.blade.php
│   ├── add-icon.blade.php
│   ├── footer.blade.php
│   ├── hour-select.blade.php
│   ├── hourly-summary.blade.php
│   ├── management-table.blade.php
│   ├── modal.blade.php
│   ├── navigation-icon.blade.php
│   ├── password-input.blade.php
│   ├── phone-number-field.blade.php
│   ├── status-badge.blade.php
│   ├── table-actions.blade.php
│   ├── table-entries.blade.php
│   └── table-pagination.blade.php
├── dashboards/
│   ├── operations.blade.php
│   ├── reservations.blade.php
│   └── resident.blade.php
├── emails/
│   ├── reservation-activity.blade.php
│   └── security-code.blade.php
├── facilities/
│   ├── partials/
│   │   ├── card-image.blade.php
│   │   ├── facility-card-content.blade.php
│   │   ├── facility-form-fields.blade.php
│   │   ├── facility-gallery.blade.php
│   │   ├── facility-reservation-form.blade.php
│   │   └── facility-view-modal.blade.php
│   ├── index.blade.php
│   └── show.blade.php
├── official-uses/
│   ├── dialogs.blade.php
│   └── index.blade.php
├── partials/
│   ├── logout.blade.php
│   ├── mobile-navigation.blade.php
│   ├── notifications.blade.php
│   ├── profile-navigation.blade.php
│   ├── pwa-registration.blade.php
│   ├── reservation-time-validation.blade.php
│   └── user-navigation.blade.php
├── payments/
│   ├── details.blade.php
│   ├── dialogs.blade.php
│   ├── index.blade.php
│   └── report.blade.php
├── profile/
│   ├── edit.blade.php
│   └── security.blade.php
├── reservations/
│   ├── admin-dialogs.blade.php
│   ├── admin-table.blade.php
│   ├── index.blade.php
│   ├── official-use-conflicts.blade.php
│   └── resident-card.blade.php
├── offline.blade.php
└── welcome.blade.php
```

## Proposed and final structure

The audited proposal was implemented without creating empty placeholder directories.

```text
resources/views/
├── admin/
│   └── analytics/
│       ├── partials/
│       │   └── admin-analytics.blade.php
│       └── index.blade.php
├── auth/
│   ├── forgot-password.blade.php
│   ├── login.blade.php
│   ├── register.blade.php
│   ├── reset-password.blade.php
│   ├── two-factor-challenge.blade.php
│   └── verify-email.blade.php
├── components/
│   ├── layouts/
│   │   ├── auth.blade.php
│   │   └── user.blade.php
│   ├── add-icon.blade.php
│   ├── footer.blade.php
│   ├── hour-select.blade.php
│   ├── hourly-summary.blade.php
│   ├── management-table.blade.php
│   ├── modal.blade.php
│   ├── navigation-icon.blade.php
│   ├── password-input.blade.php
│   ├── phone-number-field.blade.php
│   ├── status-badge.blade.php
│   ├── table-actions.blade.php
│   ├── table-entries.blade.php
│   └── table-pagination.blade.php
├── emails/
│   ├── reservation-activity.blade.php
│   └── security-code.blade.php
├── partials/
│   ├── logout.blade.php
│   ├── mobile-navigation.blade.php
│   ├── notifications.blade.php
│   ├── profile-navigation.blade.php
│   ├── pwa-registration.blade.php
│   ├── reservation-time-validation.blade.php
│   └── user-navigation.blade.php
├── resident/
│   ├── dashboards/
│   │   └── resident.blade.php
│   └── reservations/
│       └── partials/
│           └── resident-card.blade.php
├── shared/
│   ├── accounts/
│   │   ├── partials/
│   │   │   ├── confirmation.blade.php
│   │   │   ├── details.blade.php
│   │   │   ├── pagination.blade.php
│   │   │   └── status-action.blade.php
│   │   ├── index.blade.php
│   │   └── show.blade.php
│   ├── calendar/
│   │   ├── partials/
│   │   │   ├── availability.blade.php
│   │   │   ├── equipment-schedule.blade.php
│   │   │   ├── filters.blade.php
│   │   │   ├── quantity.blade.php
│   │   │   └── schedules.blade.php
│   │   └── index.blade.php
│   ├── dashboards/
│   │   ├── partials/
│   │   │   └── reservations.blade.php
│   │   └── operations.blade.php
│   ├── facilities/
│   │   ├── partials/
│   │   │   ├── card-image.blade.php
│   │   │   ├── facility-card-content.blade.php
│   │   │   ├── facility-form-fields.blade.php
│   │   │   ├── facility-gallery.blade.php
│   │   │   ├── facility-reservation-form.blade.php
│   │   │   └── facility-view-modal.blade.php
│   │   ├── index.blade.php
│   │   └── show.blade.php
│   ├── official-uses/
│   │   ├── partials/
│   │   │   └── dialogs.blade.php
│   │   └── index.blade.php
│   ├── payments/
│   │   ├── partials/
│   │   │   ├── details.blade.php
│   │   │   └── dialogs.blade.php
│   │   ├── index.blade.php
│   │   └── report.blade.php
│   ├── profile/
│   │   ├── partials/
│   │   │   └── security.blade.php
│   │   └── edit.blade.php
│   └── reservations/
│       ├── partials/
│       │   ├── admin-dialogs.blade.php
│       │   ├── admin-table.blade.php
│       │   └── official-use-conflicts.blade.php
│       └── index.blade.php
├── super_admin/
│   ├── admins/
│   │   ├── partials/
│   │   │   └── form-fields.blade.php
│   │   └── create.blade.php
│   └── analytics/
│       └── super-admin.blade.php
├── offline.blade.php
└── welcome.blade.php
```

## Every moved file

All paths in this table are relative to `resources/views/`. Files were moved, not copied. Existing basenames were preserved.

| Old path | New path |
| --- | --- |
| `accounts/confirmation.blade.php` | `shared/accounts/partials/confirmation.blade.php` |
| `accounts/details.blade.php` | `shared/accounts/partials/details.blade.php` |
| `accounts/index.blade.php` | `shared/accounts/index.blade.php` |
| `accounts/pagination.blade.php` | `shared/accounts/partials/pagination.blade.php` |
| `accounts/show.blade.php` | `shared/accounts/show.blade.php` |
| `accounts/status-action.blade.php` | `shared/accounts/partials/status-action.blade.php` |
| `admins/create.blade.php` | `super_admin/admins/create.blade.php` |
| `admins/form-fields.blade.php` | `super_admin/admins/partials/form-fields.blade.php` |
| `analytics/index.blade.php` | `admin/analytics/index.blade.php` |
| `analytics/partials/admin-analytics.blade.php` | `admin/analytics/partials/admin-analytics.blade.php` |
| `analytics/super-admin.blade.php` | `super_admin/analytics/super-admin.blade.php` |
| `calendar/availability.blade.php` | `shared/calendar/partials/availability.blade.php` |
| `calendar/equipment-schedule.blade.php` | `shared/calendar/partials/equipment-schedule.blade.php` |
| `calendar/filters.blade.php` | `shared/calendar/partials/filters.blade.php` |
| `calendar/index.blade.php` | `shared/calendar/index.blade.php` |
| `calendar/quantity.blade.php` | `shared/calendar/partials/quantity.blade.php` |
| `calendar/schedules.blade.php` | `shared/calendar/partials/schedules.blade.php` |
| `dashboards/operations.blade.php` | `shared/dashboards/operations.blade.php` |
| `dashboards/reservations.blade.php` | `shared/dashboards/partials/reservations.blade.php` |
| `dashboards/resident.blade.php` | `resident/dashboards/resident.blade.php` |
| `facilities/index.blade.php` | `shared/facilities/index.blade.php` |
| `facilities/partials/card-image.blade.php` | `shared/facilities/partials/card-image.blade.php` |
| `facilities/partials/facility-card-content.blade.php` | `shared/facilities/partials/facility-card-content.blade.php` |
| `facilities/partials/facility-form-fields.blade.php` | `shared/facilities/partials/facility-form-fields.blade.php` |
| `facilities/partials/facility-gallery.blade.php` | `shared/facilities/partials/facility-gallery.blade.php` |
| `facilities/partials/facility-reservation-form.blade.php` | `shared/facilities/partials/facility-reservation-form.blade.php` |
| `facilities/partials/facility-view-modal.blade.php` | `shared/facilities/partials/facility-view-modal.blade.php` |
| `facilities/show.blade.php` | `shared/facilities/show.blade.php` |
| `official-uses/dialogs.blade.php` | `shared/official-uses/partials/dialogs.blade.php` |
| `official-uses/index.blade.php` | `shared/official-uses/index.blade.php` |
| `payments/details.blade.php` | `shared/payments/partials/details.blade.php` |
| `payments/dialogs.blade.php` | `shared/payments/partials/dialogs.blade.php` |
| `payments/index.blade.php` | `shared/payments/index.blade.php` |
| `payments/report.blade.php` | `shared/payments/report.blade.php` |
| `profile/edit.blade.php` | `shared/profile/edit.blade.php` |
| `profile/security.blade.php` | `shared/profile/partials/security.blade.php` |
| `reservations/admin-dialogs.blade.php` | `shared/reservations/partials/admin-dialogs.blade.php` |
| `reservations/admin-table.blade.php` | `shared/reservations/partials/admin-table.blade.php` |
| `reservations/index.blade.php` | `shared/reservations/index.blade.php` |
| `reservations/official-use-conflicts.blade.php` | `shared/reservations/partials/official-use-conflicts.blade.php` |
| `reservations/resident-card.blade.php` | `resident/reservations/partials/resident-card.blade.php` |

## Complete inventory and callers before migration

The caller locations below describe the pre-refactor source, including test references. Route-name matches are excluded. Follow the mapping above to locate moved Blade callers in the final tree. All auth views also participate in the dynamic UI fixture loop; both component layouts are read by the dynamic frontend layout test.

| Original view (relative to resources/views/) | Classification / users | Direct callers |
| --- | --- | --- |
| `accounts/confirmation.blade.php` | Partial; admin + super_admin | `resources/views/accounts/index.blade.php:73`<br>`resources/views/accounts/show.blade.php:6` |
| `accounts/details.blade.php` | Partial; admin + super_admin | `resources/views/accounts/show.blade.php:5` |
| `accounts/index.blade.php` | Full page; admin + super_admin | `app/Http/Controllers/Concerns/ManagesAccounts.php:41` |
| `accounts/pagination.blade.php` | Partial; admin + super_admin | `resources/views/accounts/details.blade.php:35`<br>`resources/views/accounts/index.blade.php:59` |
| `accounts/show.blade.php` | Full page; admin + super_admin | `app/Http/Controllers/Concerns/ManagesAccounts.php:54` |
| `accounts/status-action.blade.php` | Partial; admin + super_admin | `resources/views/accounts/details.blade.php:13`<br>`resources/views/accounts/index.blade.php:53` |
| `admins/create.blade.php` | Full page; super_admin | `app/Http/Controllers/AdminController.php:37` |
| `admins/form-fields.blade.php` | Partial; super_admin | `resources/views/accounts/index.blade.php:68`<br>`resources/views/admins/create.blade.php:20` |
| `analytics/index.blade.php` | Full page; admin | `app/Http/Controllers/AnalyticsController.php:21`<br>`tests/Feature/AdminAnalyticsTest.php:184` |
| `analytics/partials/admin-analytics.blade.php` | Partial; admin | `resources/views/analytics/index.blade.php:2` |
| `analytics/super-admin.blade.php` | Full page; super_admin | `app/Http/Controllers/SuperAdminAnalyticsController.php:15`<br>`tests/Feature/AdminAnalyticsTest.php:184` |
| `auth/forgot-password.blade.php` | Authentication page; guests / verification | `app/Http/Controllers/PasswordResetController.php:22` |
| `auth/login.blade.php` | Authentication page; guests / verification | `app/Http/Controllers/AuthController.php:26` |
| `auth/register.blade.php` | Authentication page; guests / verification | `app/Http/Controllers/AuthController.php:31` |
| `auth/reset-password.blade.php` | Authentication page; guests / verification | `app/Http/Controllers/PasswordResetController.php:57` |
| `auth/two-factor-challenge.blade.php` | Authentication page; guests / verification | `app/Http/Controllers/TwoFactorLoginController.php:22` |
| `auth/verify-email.blade.php` | Authentication page; guests / verification | `app/Http/Controllers/EmailVerificationController.php:18` |
| `calendar/availability.blade.php` | Partial; all three roles | `resources/views/calendar/index.blade.php:27`<br>`resources/views/facilities/show.blade.php:118` |
| `calendar/equipment-schedule.blade.php` | Partial; all three roles | `resources/views/calendar/availability.blade.php:111` |
| `calendar/filters.blade.php` | Partial; all three roles | `resources/views/calendar/index.blade.php:17`<br>`tests/Frontend/calendar-filters.test.cjs:5` |
| `calendar/index.blade.php` | Full page; all three roles | `app/Http/Controllers/DashboardController.php:59`<br>`tests/Feature/AllSchedulesPresentationTest.php:75`<br>`tests/Feature/AllSchedulesPresentationTest.php:137`<br>`tests/Feature/FacilityFirstReservationTest.php:67`<br>`tests/Frontend/calendar-filters.test.cjs:39` |
| `calendar/quantity.blade.php` | Partial; all three roles | `resources/views/calendar/availability.blade.php:135`<br>`resources/views/calendar/availability.blade.php:145`<br>`resources/views/calendar/availability.blade.php:152`<br>`resources/views/calendar/schedules.blade.php:42` |
| `calendar/schedules.blade.php` | Partial; all three roles | `resources/views/calendar/availability.blade.php:94` |
| `components/add-icon.blade.php` | Blade component; existing alias preserved | `resources/views/accounts/index.blade.php:6`<br>`resources/views/facilities/index.blade.php:15`<br>`resources/views/official-uses/index.blade.php:5` |
| `components/footer.blade.php` | Blade component; existing alias preserved | `resources/views/components/layouts/auth.blade.php:18`<br>`resources/views/components/layouts/user.blade.php:63` |
| `components/hour-select.blade.php` | Blade component; existing alias preserved | `resources/views/facilities/partials/facility-reservation-form.blade.php:46`<br>`resources/views/facilities/partials/facility-reservation-form.blade.php:60`<br>`resources/views/official-uses/dialogs.blade.php:27`<br>`resources/views/official-uses/dialogs.blade.php:28`<br>`resources/views/official-uses/index.blade.php:64`<br>`resources/views/official-uses/index.blade.php:65`<br>`resources/views/reservations/admin-dialogs.blade.php:84`<br>`resources/views/reservations/admin-dialogs.blade.php:85`<br>`resources/views/reservations/resident-card.blade.php:111`<br>`resources/views/reservations/resident-card.blade.php:112` |
| `components/hourly-summary.blade.php` | Blade component; existing alias preserved | `resources/views/facilities/partials/facility-reservation-form.blade.php:67`<br>`resources/views/official-uses/dialogs.blade.php:29`<br>`resources/views/official-uses/index.blade.php:66`<br>`resources/views/reservations/admin-dialogs.blade.php:86`<br>`resources/views/reservations/resident-card.blade.php:114` |
| `components/layouts/auth.blade.php` | Anonymous component layout; shared | `resources/views/auth/forgot-password.blade.php:1`<br>`resources/views/auth/login.blade.php:1`<br>`resources/views/auth/register.blade.php:1`<br>`resources/views/auth/reset-password.blade.php:1`<br>`resources/views/auth/two-factor-challenge.blade.php:1`<br>`resources/views/auth/verify-email.blade.php:1`<br>`resources/views/offline.blade.php:1` |
| `components/layouts/user.blade.php` | Anonymous component layout; shared | `resources/views/accounts/index.blade.php:3`<br>`resources/views/accounts/show.blade.php:2`<br>`resources/views/admins/create.blade.php:1`<br>`resources/views/analytics/index.blade.php:1`<br>`resources/views/analytics/super-admin.blade.php:1`<br>`resources/views/calendar/index.blade.php:1`<br>`resources/views/dashboards/operations.blade.php:1`<br>`resources/views/dashboards/resident.blade.php:1`<br>`resources/views/facilities/index.blade.php:1`<br>`resources/views/facilities/show.blade.php:1`<br>`resources/views/official-uses/index.blade.php:1`<br>`resources/views/payments/index.blade.php:1`<br>`resources/views/profile/edit.blade.php:1`<br>`resources/views/reservations/index.blade.php:1` |
| `components/management-table.blade.php` | Blade component; existing alias preserved | No static caller found; retained |
| `components/modal.blade.php` | Blade component; existing alias preserved | `resources/views/accounts/confirmation.blade.php:2`<br>`resources/views/accounts/index.blade.php:61`<br>`resources/views/accounts/index.blade.php:66`<br>`resources/views/facilities/index.blade.php:47`<br>`resources/views/facilities/index.blade.php:54`<br>`resources/views/facilities/index.blade.php:73`<br>`resources/views/facilities/partials/facility-view-modal.blade.php:6`<br>`resources/views/official-uses/dialogs.blade.php:1`<br>`resources/views/official-uses/dialogs.blade.php:20`<br>`resources/views/official-uses/index.blade.php:58`<br>`resources/views/payments/dialogs.blade.php:1`<br>`resources/views/payments/dialogs.blade.php:6`<br>`resources/views/reservations/admin-dialogs.blade.php:1`<br>`resources/views/reservations/admin-dialogs.blade.php:56`<br>`resources/views/reservations/admin-dialogs.blade.php:63`<br>`resources/views/reservations/index.blade.php:142`<br>`resources/views/reservations/official-use-conflicts.blade.php:11`<br>`resources/views/reservations/resident-card.blade.php:68`<br>`resources/views/reservations/resident-card.blade.php:102` |
| `components/navigation-icon.blade.php` | Blade component; existing alias preserved | `resources/views/partials/user-navigation.blade.php:2`<br>`resources/views/partials/user-navigation.blade.php:6`<br>`resources/views/partials/user-navigation.blade.php:8`<br>`resources/views/partials/user-navigation.blade.php:11`<br>`resources/views/partials/user-navigation.blade.php:12`<br>`resources/views/partials/user-navigation.blade.php:15`<br>`resources/views/partials/user-navigation.blade.php:21`<br>`resources/views/partials/user-navigation.blade.php:28`<br>`resources/views/partials/user-navigation.blade.php:32`<br>`resources/views/partials/user-navigation.blade.php:35`<br>`resources/views/partials/user-navigation.blade.php:38`<br>`resources/views/partials/user-navigation.blade.php:44`<br>`resources/views/partials/user-navigation.blade.php:50`<br>`resources/views/partials/user-navigation.blade.php:56` |
| `components/password-input.blade.php` | Blade component; existing alias preserved | `resources/views/auth/login.blade.php:32`<br>`resources/views/auth/register.blade.php:61`<br>`resources/views/auth/register.blade.php:69`<br>`resources/views/auth/reset-password.blade.php:23`<br>`resources/views/auth/reset-password.blade.php:30` |
| `components/phone-number-field.blade.php` | Blade component; existing alias preserved | `resources/views/admins/form-fields.blade.php:17`<br>`resources/views/auth/register.blade.php:44`<br>`resources/views/profile/edit.blade.php:67` |
| `components/status-badge.blade.php` | Blade component; existing alias preserved | `resources/views/dashboards/resident.blade.php:41` |
| `components/table-actions.blade.php` | Blade component; existing alias preserved | `resources/views/accounts/index.blade.php:53`<br>`resources/views/official-uses/index.blade.php:41`<br>`resources/views/payments/index.blade.php:32`<br>`resources/views/reservations/admin-table.blade.php:25` |
| `components/table-entries.blade.php` | Blade component; existing alias preserved | No static caller found; retained |
| `components/table-pagination.blade.php` | Blade component; existing alias preserved | `resources/views/official-uses/index.blade.php:54`<br>`resources/views/payments/index.blade.php:35`<br>`resources/views/reservations/admin-table.blade.php:43`<br>`tests/Feature/FrontendAssetContractsTest.php:76` |
| `dashboards/operations.blade.php` | Full page; admin + super_admin | `app/Http/Controllers/DashboardController.php:18`<br>`tests/Feature/LoginDestinationTest.php:43` |
| `dashboards/reservations.blade.php` | Partial; admin + super_admin | `resources/views/dashboards/operations.blade.php:53`<br>`resources/views/dashboards/operations.blade.php:59` |
| `dashboards/resident.blade.php` | Full page; resident (stored role: user) | `app/Http/Controllers/DashboardController.php:45`<br>`tests/Feature/FacilityFirstReservationTest.php:51`<br>`tests/Feature/LoginDestinationTest.php:43` |
| `emails/reservation-activity.blade.php` | Email template; shared | `app/Notifications/ReservationActivity.php:137` |
| `emails/security-code.blade.php` | Email template; shared | `app/Notifications/SecurityCode.php:21` |
| `facilities/index.blade.php` | Full page; all three roles | `app/Http/Controllers/FacilityController.php:97`<br>`tests/Frontend/page-handlers.test.cjs:55` |
| `facilities/partials/card-image.blade.php` | Partial; all three roles | `resources/views/facilities/index.blade.php:27`<br>`resources/views/facilities/index.blade.php:135` |
| `facilities/partials/facility-card-content.blade.php` | Partial; all three roles | `resources/views/facilities/index.blade.php:30`<br>`resources/views/facilities/index.blade.php:138` |
| `facilities/partials/facility-form-fields.blade.php` | Partial; all three roles | `resources/views/facilities/index.blade.php:51`<br>`resources/views/facilities/index.blade.php:76` |
| `facilities/partials/facility-gallery.blade.php` | Partial; all three roles | `resources/views/facilities/partials/facility-view-modal.blade.php:8`<br>`resources/views/facilities/show.blade.php:21` |
| `facilities/partials/facility-reservation-form.blade.php` | Partial; all three roles | `resources/views/facilities/show.blade.php:101`<br>`resources/views/facilities/show.blade.php:132`<br>`tests/Frontend/time-layout.cjs:10` |
| `facilities/partials/facility-view-modal.blade.php` | Partial; all three roles | `resources/views/facilities/index.blade.php:46` |
| `facilities/show.blade.php` | Full page; all three roles | `app/Http/Controllers/FacilityController.php:128`<br>`tests/Feature/OvernightReservationTest.php:73`<br>`tests/Feature/ResourceDetailRoutesTest.php:54` |
| `official-uses/dialogs.blade.php` | Partial; admin + super_admin | `resources/views/official-uses/index.blade.php:56` |
| `official-uses/index.blade.php` | Full page; admin + super_admin | `app/Http/Controllers/OfficialUseController.php:37`<br>`tests/Feature/OfficialUseListingTest.php:57` |
| `offline.blade.php` | Public offline page; retained | `routes/web.php:27` |
| `partials/logout.blade.php` | Global partial; shared | `resources/views/components/layouts/user.blade.php:43`<br>`resources/views/partials/mobile-navigation.blade.php:38` |
| `partials/mobile-navigation.blade.php` | Global partial; shared | `resources/views/components/layouts/user.blade.php:54` |
| `partials/notifications.blade.php` | Global partial; shared | `resources/views/components/layouts/user.blade.php:41` |
| `partials/profile-navigation.blade.php` | Global partial; shared | `resources/views/components/layouts/user.blade.php:42` |
| `partials/pwa-registration.blade.php` | Global partial; shared | `resources/views/components/layouts/auth.blade.php:20`<br>`resources/views/components/layouts/user.blade.php:67`<br>`tests/Frontend/page-handlers.test.cjs:7`<br>`tests/Frontend/page-handlers.test.cjs:11` |
| `partials/reservation-time-validation.blade.php` | Global partial; shared | `resources/views/facilities/index.blade.php:168`<br>`resources/views/facilities/show.blade.php:140`<br>`resources/views/official-uses/index.blade.php:76`<br>`resources/views/reservations/index.blade.php:176` |
| `partials/user-navigation.blade.php` | Global partial; shared | `resources/views/components/layouts/user.blade.php:50`<br>`resources/views/partials/mobile-navigation.blade.php:34` |
| `payments/details.blade.php` | Partial; admin + super_admin | `resources/views/payments/dialogs.blade.php:2`<br>`resources/views/payments/dialogs.blade.php:9` |
| `payments/dialogs.blade.php` | Partial; admin + super_admin | `resources/views/payments/index.blade.php:36` |
| `payments/index.blade.php` | Full page; admin + super_admin | `app/Http/Controllers/PaymentController.php:33` |
| `payments/report.blade.php` | PDF report view; admin + super_admin | `app/Support/PaymentReport.php:74`<br>`tests/Feature/PaymentsManagementTest.php:669` |
| `profile/edit.blade.php` | Full page; all three roles | `app/Http/Controllers/ProfileController.php:24`<br>`tests/Frontend/page-handlers.test.cjs:40` |
| `profile/security.blade.php` | Partial; all three roles | `resources/views/profile/edit.blade.php:138`<br>`tests/Frontend/page-handlers.test.cjs:38` |
| `reservations/admin-dialogs.blade.php` | Partial; all three roles | `resources/views/reservations/admin-table.blade.php:45` |
| `reservations/admin-table.blade.php` | Partial; all three roles | `resources/views/reservations/index.blade.php:119` |
| `reservations/index.blade.php` | Full page; all three roles | `app/Http/Controllers/ReservationPageController.php:35`<br>`app/Http/Controllers/ReservationPageController.php:45`<br>`tests/Feature/ResidentReservationListingTest.php:66`<br>`tests/Feature/ResidentReservationListingTest.php:191`<br>`tests/Frontend/calendar-filters.test.cjs:40` |
| `reservations/official-use-conflicts.blade.php` | Partial; all three roles | `resources/views/reservations/admin-dialogs.blade.php:3`<br>`resources/views/reservations/admin-dialogs.blade.php:65`<br>`resources/views/reservations/admin-table.blade.php:24`<br>`resources/views/reservations/resident-card.blade.php:61` |
| `reservations/resident-card.blade.php` | Partial; resident | `resources/views/reservations/index.blade.php:134` |
| `welcome.blade.php` | Unreferenced starter page; retained | No static caller found; retained |

## Shared views deliberately outside role folders

- `shared/accounts/`: account index, details, status confirmation/actions and pagination for admin and super_admin.
- `shared/calendar/`: the calendar and availability/schedule/filter/quantity partials for all roles.
- `shared/dashboards/`: one operations dashboard and its reservation list for both administrative roles.
- `shared/facilities/`: unified facility/equipment pages and partials; role branches remain intact.
- `shared/official-uses/`: existing management UI for both administrative roles.
- `shared/payments/`: payment management, dialogs/details and PDF report for both administrative roles.
- `shared/profile/`: profile and security for all roles.
- `shared/reservations/`: shared index/conflict UI plus administrative table/dialogs.
- Existing `components/`, `partials/`, `auth/`, `emails/`, and public `offline.blade.php` remain at their original locations. The unreferenced welcome template is also retained.

## Updated references

Only view-name literals, nested include targets, view assertions, and explicit test fixture paths changed. The table lists every changed source file and final line number; moved files with unchanged contents are covered by the migration table.

| File | Updated lines |
| --- | --- |
| `app/Http/Controllers/AdminController.php` | 37 |
| `app/Http/Controllers/AnalyticsController.php` | 21 |
| `app/Http/Controllers/Concerns/ManagesAccounts.php` | 41, 54 |
| `app/Http/Controllers/DashboardController.php` | 18, 45, 59 |
| `app/Http/Controllers/FacilityController.php` | 97, 128 |
| `app/Http/Controllers/OfficialUseController.php` | 37 |
| `app/Http/Controllers/PaymentController.php` | 33 |
| `app/Http/Controllers/ProfileController.php` | 24 |
| `app/Http/Controllers/ReservationPageController.php` | 35, 45 |
| `app/Http/Controllers/SuperAdminAnalyticsController.php` | 15 |
| `app/Support/PaymentReport.php` | 74 |
| `resources/views/shared/accounts/partials/details.blade.php` | 13, 35 |
| `resources/views/shared/accounts/index.blade.php` | 53, 59, 68, 73 |
| `resources/views/shared/accounts/show.blade.php` | 5, 6 |
| `resources/views/super_admin/admins/create.blade.php` | 20 |
| `resources/views/admin/analytics/index.blade.php` | 2 |
| `resources/views/shared/calendar/partials/availability.blade.php` | 94, 111, 135, 145, 152 |
| `resources/views/shared/calendar/index.blade.php` | 17, 27 |
| `resources/views/shared/calendar/partials/schedules.blade.php` | 42 |
| `resources/views/shared/dashboards/operations.blade.php` | 53, 59 |
| `resources/views/shared/facilities/index.blade.php` | 27, 30, 46, 51, 76, 135, 138 |
| `resources/views/shared/facilities/partials/facility-view-modal.blade.php` | 8 |
| `resources/views/shared/facilities/show.blade.php` | 21, 101, 118, 132 |
| `resources/views/shared/official-uses/index.blade.php` | 56 |
| `resources/views/shared/payments/partials/dialogs.blade.php` | 2, 9 |
| `resources/views/shared/payments/index.blade.php` | 36 |
| `resources/views/shared/profile/edit.blade.php` | 138 |
| `resources/views/shared/reservations/partials/admin-dialogs.blade.php` | 3, 65 |
| `resources/views/shared/reservations/partials/admin-table.blade.php` | 24, 45 |
| `resources/views/shared/reservations/index.blade.php` | 119, 134 |
| `resources/views/resident/reservations/partials/resident-card.blade.php` | 61 |
| `tests/Feature/AdminAnalyticsTest.php` | 184 |
| `tests/Feature/AllSchedulesPresentationTest.php` | 75, 137 |
| `tests/Feature/FacilityFirstReservationTest.php` | 51, 67 |
| `tests/Feature/LoginDestinationTest.php` | 43 |
| `tests/Feature/OfficialUseListingTest.php` | 57 |
| `tests/Feature/OvernightReservationTest.php` | 73 |
| `tests/Feature/PaymentsManagementTest.php` | 669 |
| `tests/Feature/ResidentReservationListingTest.php` | 66, 191 |
| `tests/Feature/ResourceDetailRoutesTest.php` | 54 |
| `tests/Frontend/calendar-filters.test.cjs` | 5, 39, 40 |
| `tests/Frontend/page-handlers.test.cjs` | 38, 40, 55 |
| `tests/Frontend/time-layout.cjs` | 10 |

There were no relocated route-returned views, middleware view references, or mailable/notification email view references to update. Both notification classes still use the unchanged `emails/` templates. Runtime JavaScript, CSS, PWA files, route definitions, models, migrations, authorization, and business logic were unchanged.

## Verification results

| Check | Baseline | After migration |
| --- | --- | --- |
| Full PHP suite: `php artisan test --compact` | 667 tests: 653 passed, 14 failed; 9,614 assertions (436.652 seconds) | 667 tests: 652 passed, 15 failed; 9,606 assertions (389.374 seconds). The one additional stale test expectation was then corrected and verified below. |
| Final reservation-listing rerun: `php artisan test --compact --filter=ResidentReservationListingTest` | Included in full suite | 18 passed; 454 assertions (27.401 seconds) |
| Frontend unit checks: `node --test tests/Frontend/*.test.cjs` | 51 passed | 51 passed |
| Isolated email tests: `php artisan test --compact --filter=ReservationEmailNotificationTest` | Not run separately | 30 passed; 378 assertions (10.423 seconds) |
| Role/auth fixtures: `php tests/Frontend/ui-consistency-fixtures.php` | Rendered successfully | Rendered successfully |
| Headless Chrome: `node tests/Frontend/ui-consistency-browser.cjs` | 210 checks: 135 passed, 75 failed | Exactly identical results for all 210 checks |
| `php artisan view:cache`, then `php artisan view:clear` | Not required for baseline | Both succeeded; compiled views cleared after verification |
| Blade inventory | 73 files | 73 files; 41 moved, 32 retained; no missing files or obsolete duplicates |
| `php artisan route:list --json` | 67 routes | All 67 definitions identical, including URLs, names, methods and middleware |
| Source integrity against clean HEAD | Original source | All tracked app/resources/routes/tests/bootstrap/config/database/public files match exactly after applying only the documented view-reference substitutions |
| Obsolete view references | Inventoried | No obsolete view calls, includes, assertions or test file paths |
| `git diff --check` | Clean tree | Passed |

The integrity comparison covers the complete Blade contents, including HTML, styles, scripts, IDs, data attributes, hooks and control flow. It also covers unchanged CSS/JavaScript assets, PWA code, routes, authentication, permissions, models and business logic. The frontend tests exercise DataTable mutations, account confirmation dialogs, filters, PWA caching, notifications and time validation. The fixture renderer covers all three dashboards, shared feature pages, both analytics pages and all six authentication pages.

The full post-migration run exposed one additional reference: `ResidentReservationListingTest::test_empty_and_explicit_null_values_keep_the_current_view_defaults` compared `$view->name()` directly with the old `reservations.index` literal. Only that expected name was updated to `shared.reservations.index`; the complete 18-test class then passed. The full 667-test suite was not repeated after this test-only correction. All application code had already been exercised by the full run. A broader literal scan found only intentionally unchanged route-name uses, and the final test file was verified to differ from HEAD solely in its two view-name expectations.

### Existing failures and verification limits

- The same 14 PHP failures occur in both full runs, all in `ReservationEmailNotificationTest`: queue commands return 12 rather than the asserted 0. The installed Laravel worker defines 12 as `EXIT_MEMORY_LIMIT` (`vendor/laravel/framework/src/Illuminate/Queue/Worker.php:35`). All 30 tests in that class pass in a fresh process after migration, supporting a full-suite memory-limit issue rather than an email-view regression. No queue configuration or email behavior was changed.
- The browser baseline and final run have the same 52 `Navigation icon shrinks`, 9 `Drawer current page missing or duplicated`, and 14 `Super Admin navigation order` failures. No UI fixes were made within this folder refactor.
- Browser verification was automated headless Chrome against generated fixtures at 1440, 1280, 1024, 768, 390 and 320 pixels. No manual or live-server browser verification was performed.
- Dedicated MySQL integration scripts were not run; this refactor's PHP suite uses isolated in-memory SQLite.
- Historical documentation still contains 52 old-path/name occurrences. These describe earlier implementations and were not rewritten. This audit also deliberately records the original paths. These are not runtime references. Historical documents: `PAYMENTS_DATATABLE_REPORT.md`, `docs/equipment-capacity-availability.md`, `docs/facility-first-reservations.md`, `docs/hourly-booking.md`, `docs/payment-refund-workflow.md`, `docs/resident-reservation-presentation.md`, `docs/resource-detail-urls.md`, `docs/resource-separation-report.md`, `docs/unified-resident-booking.md`.
- No unresolved relocated dynamic reference was found. The three templates without discovered callers remain at their original paths.


