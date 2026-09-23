Unavailable Red
# SaaS Architecture for ereserve

# Status: FOR REVIEW

# Overview

ereserve is a barangay-based facility and equipment reservation system built with Laravel.

The current project already supports login, registration, facility browsing, reservation submission, reservation viewing, and admin management for facilities and reservations.

The current project is focused on Barangay Washington. This SaaS architecture describes the required updates to support multiple barangays using barangay-based data scoping.

# Purpose

The purpose of this document is to define a simple SaaS architecture for ereserve where users, admins, and super admins access reservation data based on their assigned barangay.

# Scope

This specification covers:

User barangay assignment.

Role-based access for user, admin, and super_admin.

Barangay filtering for facilities if a facilities table is added.

Barangay filtering for reservations.

Admin management rules.

UI updates for showing the logged-in user's role and barangay.

# Included

Existing route `/login` for login.

Existing route `/register` for registration.

Existing route `/dashboard` for the dashboard and reservation calendar.

Existing route `/facilities` for facility browsing and admin facility management.

Existing route `/facilities/{slug}` for facility details.

Existing route `/facilities/{slug}/reservations` for reservation creation.

Existing route `/reservations` for reservation lists and admin reservation management.

Existing route `/reservations/{reservation}/accept` for approving reservations.

Existing route `/reservations/{reservation}/reject` for declining reservations.

Existing route `/profile` for profile management.

Existing route `/logout` for logout.

# Not Included

No public API routes are currently confirmed in `routes/web.php`.

No `facilities` database table is currently confirmed in `database/migrations`.

No existing `barangay` field is currently confirmed in `users`.

No existing `super_admin` role is currently implemented.

No existing admin creation screen is currently confirmed.

# Roles

Current project roles:

`resident` exists as the current default user role in `database/migrations/2026_07_16_000001_add_role_to_users_table.php`.

`admin` exists in `app/Http/Controllers/AuthController.php`, `app/Http/Controllers/FacilityController.php`, `app/Http/Controllers/ReservationController.php`, `app/Http/Controllers/ReservationPageController.php`, and `app/Http/Controllers/DashboardController.php`.

Required SaaS roles:

`user` belongs to one barangay.

`admin` manages one barangay.

`super_admin` manages all barangays.

The current `resident` role should be changed to `user` or mapped to `user` during the SaaS update.

# Functional Requirements

Users can register, log in, browse facilities, create reservations, view their own reservations, and update their profile.

Admins can manage facilities through `/facilities`.

Admins can view, approve, and decline reservation requests through `/reservations`.

Admins can only access data from their assigned barangay.

Super admins can access data from all barangays.

Only super_admin can create admins.

Admin cannot access reservations or facilities from other barangays.

The barangay list must use these exact values:

Alang-alang, Alegria, Anomar, Aurora, Balibayon, Baybay, Bilabid, Bitaugan, Bonifacio, Buenavista, Cabongbongan, Cagniog, Cagutsan, Canlanipa, Cantiasay, Capalayan, Catadman, Danao, Danawan, Day-asan, Ipil, Libuac, Lipata, Lisondra, Luna, Mabini, Mabua, Manyagao, Mapawa, Mat-i, Nabago, Nonoc, Orok, Poctoy, Punta Bilar, Quezon, Rizal, Sabang, San Isidro, San Jose, San Juan, San Pedro, San Roque, Serna, Sidlakan, Silop, Sugbay, Sukailang, Taft, Talisay, Togbongon, Trinidad, Washington, Zaragoza

# Non-Functional Requirements

Barangay filtering must be applied on the server side.

Users must not be trusted to provide barangay values for protected admin actions.

Reservation conflict checking must continue to prevent overlapping pending or approved reservations.

Existing validation rules for reservation date, time, purpose, and attendees must remain active.

The UI must continue using `public/css/app.css`.

The current main UI colors are `#eefafa`, `#ffffff`, `#13233d`, `#475569`, `#cbd5e1`, `#2563eb`, `#08a83f`, and `#dc2626`.

# Technical Design

Existing routing is defined in `routes/web.php`.

Authentication is handled by `app/Http/Controllers/AuthController.php`.

Dashboard logic is handled by `app/Http/Controllers/DashboardController.php`.

Facility browsing and management are handled by `app/Http/Controllers/FacilityController.php`.

Reservation creation, approval, and rejection are handled by `app/Http/Controllers/ReservationController.php`.

Reservation list filtering is handled by `app/Http/Controllers/ReservationPageController.php`.

Facilities currently use `app/Support/FacilityCatalog.php` and session data, not a confirmed `facilities` table.

Reservations use `app/Models/Reservation.php` and the `reservations` table.

Availability checks use `app/Support/ReservationAvailability.php`.

Layouts are in `resources/views/components/layouts/auth.blade.php` and `resources/views/components/layouts/user.blade.php`.

Registration form is in `resources/views/auth/register.blade.php`.

Dashboard view is in `resources/views/dashboard.blade.php`.

Reservation management view is in `resources/views/reservations/index.blade.php`.

Facility views are in `resources/views/facilities.blade.php` and `resources/views/facility-show.blade.php`.

The layouts load CSS from `{{ asset('css/app.css') }}`.

Vite config exists in `vite.config.js`, but the confirmed layouts currently load `public/css/app.css`.

# Database Design

Confirmed `users` fields:

`id`

`name`

`email`

`email_verified_at`

`password`

`remember_token`

`created_at`

`updated_at`

`role`

Required `users` update:

Add `barangay`.

Update `role` values to support `user`, `admin`, and `super_admin`.

Confirmed `reservations` fields:

`id`

`user_id`

`facility_slug`

`facility_name`

`category`

`location`

`reservation_date`

`start_time`

`end_time`

`purpose`

`attendees`

`status`

`created_at`

`updated_at`

Required `reservations` update:

Add `barangay` so reservations can be filtered directly by barangay.

Facilities:

No `facilities` database table is currently confirmed.

If a `facilities` table is added, include `barangay` and apply barangay filtering to it.

Example migration update:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('barangay')->after('email');
    $table->string('role')->default('user')->change();
});

Schema::table('reservations', function (Blueprint $table) {
    $table->string('barangay')->after('user_id')->index();
});
```

# User Model

Current file: `app/Models/User.php`.

Current fillable fields:

`name`

`email`

`password`

`role`

Required fillable fields:

`name`

`email`

`password`

`role`

`barangay`

Required model update:

```php
#[Fillable(['name', 'email', 'password', 'role', 'barangay'])]
```

# Registration Logic

Current registration is in `app/Http/Controllers/AuthController.php`.

Current validation:

```php
'name' => ['required', 'string', 'max:255'],
'email' => ['required', 'email', 'max:255', 'unique:users,email'],
'password' => ['required', 'confirmed', Password::min(8)],
'role' => ['required', 'in:resident,admin'],
```

Required validation:

```php
'name' => ['required', 'string', 'max:255'],
'email' => ['required', 'email', 'max:255', 'unique:users,email'],
'password' => ['required', 'confirmed', Password::min(8)],
'barangay' => ['required', 'in:Alang-alang,Alegria,Anomar,Aurora,Balibayon,Baybay,Bilabid,Bitaugan,Bonifacio,Buenavista,Cabongbongan,Cagniog,Cagutsan,Canlanipa,Cantiasay,Capalayan,Catadman,Danao,Danawan,Day-asan,Ipil,Libuac,Lipata,Lisondra,Luna,Mabini,Mabua,Manyagao,Mapawa,Mat-i,Nabago,Nonoc,Orok,Poctoy,Punta Bilar,Quezon,Rizal,Sabang,San Isidro,San Jose,San Juan,San Pedro,San Roque,Serna,Sidlakan,Silop,Sugbay,Sukailang,Taft,Talisay,Togbongon,Trinidad,Washington,Zaragoza'],
'role' => ['required', 'in:user'],
```

Public registration should create normal users only.

Admin and super_admin accounts must not be created from the public registration form.

`resources/views/auth/register.blade.php` must add a barangay select field and remove public selection of `admin`.

# Role-Based Access Control

Current admin checks use:

```php
$request->user()->role === 'admin'
```

Required access rules:

`user` can browse facilities and create reservations.

`admin` can manage facilities and reservations for their barangay only.

`super_admin` can manage all barangays.

Controller logic can use:

```php
private function isAdminOrSuperAdmin(Request $request): bool
{
    return in_array($request->user()->role, ['admin', 'super_admin'], true);
}
```

Admin-only checks in `FacilityController` and `ReservationController` must allow `admin` and `super_admin` where management is required.

Super admin-only checks must be used for admin creation.

# Data Scoping

Required rule:

```php
if (auth()->user()->role === 'super_admin') {
    // no filtering
} else {
    $query->where('barangay', auth()->user()->barangay);
}
```

Apply this only to:

Facilities, if a `facilities` table is added.

Reservations, because the `reservations` table exists.

Example reservations query:

```php
$query = Reservation::query();

if (auth()->user()->role !== 'super_admin') {
    $query->where('barangay', auth()->user()->barangay);
}
```

Example facility query if facilities become database-backed:

```php
$query = Facility::query();

if (auth()->user()->role !== 'super_admin') {
    $query->where('barangay', auth()->user()->barangay);
}
```

Reservation creation must save the user's barangay:

```php
Reservation::create([
    'user_id' => $request->user()->id,
    'barangay' => $request->user()->barangay,
    'facility_slug' => $facility['slug'],
    'facility_name' => $facility['name'],
    'category' => $facility['category'],
    'location' => $facility['location'],
    'reservation_date' => $validated['reservation_date'],
    'start_time' => $validated['start_time'],
    'end_time' => $validated['end_time'],
    'purpose' => $validated['purpose'],
    'attendees' => $validated['attendees'],
    'status' => 'pending',
]);
```

Reservation availability checks in `app/Support/ReservationAvailability.php` must include barangay filtering after the `reservations.barangay` field exists.

# Admin Management

Only `super_admin` can create admin accounts.

Admin accounts must have exactly one barangay.

Admin-created or super-admin-created users must use the allowed barangay list.

Admins must not be allowed to change their own barangay to access other data.

Admins must not approve or reject reservations outside their barangay.

The current project has no confirmed admin creation screen, so this must be added as a new feature if required.

# UI Requirements

Update `resources/views/components/layouts/user.blade.php` to show:

```blade
Role: {{ auth()->user()->role }}
Barangay: {{ auth()->user()->barangay }}
```

Admin sees only their barangay data.

Super admin sees all barangay data.

Registration view `resources/views/auth/register.blade.php` must include the barangay select field.

Profile view `resources/views/profile.blade.php` may show barangay as account information.

Use existing CSS path `public/css/app.css`.

Keep the UI consistent with the current colors defined in `public/css/app.css`.

# API Requirements

No API routes are currently confirmed.

All confirmed application routes are web routes in `routes/web.php`.

If API routes are added later, they must use the same role and barangay scoping rules.

# Acceptance Criteria

`users` table has `barangay` and `role`.

`User` model fillable fields include `barangay`.

Public registration creates only `user` accounts.

Registration requires a barangay from the exact allowed barangay list.

Admin creation is only available to `super_admin`.

Admin users can view only records where `barangay` equals `auth()->user()->barangay`.

Super admin users can view all barangay records.

Reservations store the barangay of the requesting user.

Reservation lists in `/reservations` are scoped by barangay for admin and user roles.

Dashboard admin reservation summaries are scoped by barangay for admin role.

Reservation approve and reject actions cannot affect another barangay.

The layout shows the authenticated user's role and barangay.

# Test Cases

Register a user with a valid barangay and confirm the user is saved with role `user`.

Try to register with a barangay not in the allowed list and confirm validation fails.

Log in as a user and confirm `/facilities` is accessible.

Log in as a user and create a reservation through `/facilities/{slug}/reservations`.

Confirm the new reservation stores the user's barangay.

Log in as an admin assigned to Washington and confirm `/reservations` shows only Washington reservations.

Log in as an admin assigned to Washington and confirm reservations from another barangay are not visible.

Log in as an admin and confirm approving another barangay reservation is blocked.

Log in as a super_admin and confirm `/reservations` can show all barangays.

Confirm only super_admin can create admin accounts.

Confirm `Role: {{ auth()->user()->role }}` appears in the layout.

Confirm `Barangay: {{ auth()->user()->barangay }}` appears in the layout.

# Future Enhancements

Add a database-backed `facilities` table with a `barangay` field.

Replace session-based facility changes in `App\Support\FacilityCatalog` with database records.

Add a super admin dashboard for barangay-wide reporting.

Add admin account management screens.

Add reservation reports by barangay, facility, date, and status.

Add audit logs for admin and super_admin actions.
