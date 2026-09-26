You are working inside my existing Laravel eReserve project.

FEATURE:
Account Management

GOAL:
Implement a clear account-management hierarchy for eReserve:

SUPER ADMIN → Admin Management
BARANGAY ADMIN → Resident Management

The Super Admin manages Barangay Admin accounts.

Each Barangay Admin manages resident/user accounts belonging ONLY to their
assigned barangay.

Normal users/residents have no account-management privileges.

IMPORTANT:
Before implementing anything, inspect the existing project architecture.

Reuse existing:
- authentication
- roles
- middleware
- controllers
- models
- routes
- Blade layouts
- sidebar/navigation
- barangay scoping
- Admin creation functionality
- UI components

Do NOT duplicate existing functionality.
Do NOT redesign unrelated parts of the application.

==================================================
ROLE HIERARCHY
==================================================

The existing roles are:

- super_admin
- admin
- user

Management structure:

SUPER ADMIN
    ↓
Admin Management
    ↓
Barangay Admins


BARANGAY ADMIN
    ↓
Resident Management
    ↓
Residents from the Admin's barangay only


USER / RESIDENT
    ↓
No account-management privileges

==================================================
PART 1 — SUPER ADMIN: ADMIN MANAGEMENT
==================================================

The Super Admin should manage role = admin accounts only.

If Admin Management or Create Admin functionality already exists,
EXTEND and reuse it.

Do NOT create a duplicate Admin management system.

Admin Management should display:

- Admin Name
- Email
- Assigned Barangay
- Account Status
- Date Created
- Actions

Add:

- Search by name/email
- Filter by barangay
- Filter by account status
- Pagination

==================================================
SUPER ADMIN ACTIONS
==================================================

Super Admin can:

- View Admin details
- Create Admin
- Activate Admin
- Deactivate Admin

Use the existing Create Admin functionality if already implemented.

Do NOT add permanent Admin deletion.

Do NOT display normal residents/users inside Admin Management.

Do NOT allow Super Admin to accidentally deactivate itself.

Do NOT treat super_admin accounts as normal Admin accounts.

==================================================
PART 2 — BARANGAY ADMIN: RESIDENT MANAGEMENT
==================================================

Add:

Resident Management

to the existing Barangay Admin sidebar/navigation.

Resident Management handles:

role = user

accounts only.

IMPORTANT:

A Barangay Admin can ONLY see and manage residents belonging to the
same barangay as the authenticated Admin.

Example:

Authenticated Admin:

role = admin
barangay = Washington

Resident Management query must only return:

role = user
barangay = Washington

The Washington Admin must NOT see or manage:

- Residents from another barangay
- Other Admins
- Super Admin
- Accounts outside Washington

==================================================
RESIDENT MANAGEMENT PAGE
==================================================

Display:

- Name
- Email
- Barangay
- Account Status
- Date Registered
- Actions

Add:

- Search by name/email
- Filter by account status
- Pagination

Use the existing eReserve Admin dashboard design.

Keep existing:

- typography
- cards
- tables
- buttons
- sidebar
- spacing
- responsive behavior
- confirmation dialog style

Do NOT redesign the Admin dashboard.

==================================================
VIEW RESIDENT
==================================================

Add a View action.

The Barangay Admin should be able to view:

RESIDENT INFORMATION

- Name
- Email
- Barangay
- Account Status
- Registration Date

RESERVATION ACTIVITY

- Facility/Equipment
- Reservation Date
- Start Time
- End Time
- Reservation Status
- Total Amount / Payment information if already supported

Only use fields and relationships that actually exist.

Do NOT invent database fields or fake information.

==================================================
ACCOUNT ACTIVATION / DEACTIVATION
==================================================

Use account activation/deactivation instead of permanent deletion.

Barangay Admin:

- Activate Resident
- Deactivate Resident

Super Admin:

- Activate Admin
- Deactivate Admin

IMPORTANT:

First inspect the existing users table and authentication logic.

If account activation/deactivation already exists, reuse it.

If it does NOT exist:

DO NOT immediately create a complicated status system.

Determine the smallest appropriate schema change.

Preferred simple approach if compatible with the existing architecture:

is_active BOOLEAN

Example:

is_active = true
→ account can log in

is_active = false
→ account cannot log in

If a different existing mechanism fits the project better, reuse that instead.

==================================================
DEACTIVATED ACCOUNT LOGIN
==================================================

A deactivated account must NOT be able to log in.

Authentication should conceptually enforce:

Valid credentials?
        ↓
Is account active?
        ↓
YES → Login
NO  → Reject login

Display a clear message such as:

"Your account has been deactivated. Please contact your barangay administrator."

Do not expose sensitive system information.

==================================================
PRESERVE HISTORICAL DATA
==================================================

Deactivation must NOT delete:

- Reservations
- Payments
- Notifications
- Facility history
- Reservation history
- Other legitimate historical records

The account should remain in the database.

It can be reactivated later.

==================================================
CONFIRMATION DIALOGS
==================================================

Before deactivating a resident:

"Deactivate this resident?"

"This resident will no longer be able to log in.
Their reservation and payment history will remain available."

Buttons:

Cancel
Deactivate


Before deactivating an Admin:

"Deactivate this admin?"

"This admin will no longer be able to access the Barangay Admin dashboard.
Existing barangay records will remain unchanged."

Buttons:

Cancel
Deactivate

==================================================
BACKEND SECURITY — VERY IMPORTANT
==================================================

Do NOT enforce permissions only by hiding buttons in Blade.

All restrictions must also be enforced on the backend.

--------------------------------------------------
BARANGAY ADMIN
--------------------------------------------------

For Resident Management, every target account must satisfy:

target.role = 'user'

AND

target.barangay = authenticated_admin.barangay

Example:

Washington Admin:

CAN:
- View Washington residents
- Manage Washington residents

CANNOT:
- View Mabua residents
- Manage Mabua residents
- Manage another Admin
- Manage Super Admin

--------------------------------------------------
SUPER ADMIN
--------------------------------------------------

For Admin Management:

target.role = 'admin'

Super Admin CAN:

- View Admins
- Create Admins
- Activate Admins
- Deactivate Admins

Super Admin CANNOT:

- Manage itself through Admin Management
- Treat normal residents as Admins
- Accidentally modify protected super_admin accounts

==================================================
DIRECT URL PROTECTION
==================================================

Protect against ID manipulation.

Example:

/admin/residents/15

Do NOT simply load:

User::findOrFail($id)

and assume the user is authorized.

The request must verify that the target:

role = user

AND

barangay = authenticated Admin's barangay

A Washington Admin manually entering the ID of a resident from another
barangay must receive 403 or 404 according to the project's existing
authorization convention.

Likewise:

/super-admin/admins/{id}

must only operate on role = admin accounts.

==================================================
NO PERMANENT DELETE
==================================================

Do NOT implement:

Delete Resident
Delete Admin

Use:

Activate
Deactivate

instead.

This protects historical relationships.

==================================================
NAVIGATION
==================================================

Use the existing sidebar structure.

For BARANGAY ADMIN, include:

Admin Dashboard
Facility Management
Resident Management
Analytics
Profile

Preserve any other existing navigation items.

Place Resident Management logically with the existing management sections.


For SUPER ADMIN, include:

Super Admin Dashboard
Admin Management
Profile

Preserve any other existing Super Admin navigation items.

If Admin Management already exists, reuse it.

==================================================
DUMMY / TEST DATA SAFETY
==================================================

This feature is NOT responsible for cleaning dummy/test records.

Do NOT automatically delete dummy users.

Do NOT modify:

database/factories/UserFactory.php

Do NOT inspect or modify:

database/seeders/DatabaseSeeder.php

Do NOT modify existing Super Admin credentials.

==================================================
TEST DATABASE SAFETY
==================================================

The project currently has database-isolation safeguards.

Preserve them.

Tests MUST use the existing isolated SQLite :memory: database.

NEVER run automated tests against the real eReserve MySQL database.

Do NOT:

- remove the TestCase database guard
- weaken the database guard
- point PHPUnit to MySQL
- use the development MySQL database for tests

Factories may be used only inside the isolated testing environment.

==================================================
TESTS — RESIDENT MANAGEMENT
==================================================

Add tests confirming:

1. Admin can access Resident Management.

2. Normal resident cannot access Resident Management.

3. Admin sees residents from their own barangay.

4. Admin does NOT see residents from another barangay.

5. Admin cannot access another barangay resident by manually changing
   the URL ID.

6. Admin cannot manage another Admin.

7. Admin cannot manage Super Admin.

8. Admin can view resident details.

9. Admin can deactivate a resident.

10. Deactivated resident cannot log in.

11. Admin can reactivate a resident.

12. Reactivated resident can log in again.

13. Resident reservation history remains after deactivation.

14. Payment/history records remain after deactivation.

==================================================
TESTS — ADMIN MANAGEMENT
==================================================

Add tests confirming:

1. Super Admin can access Admin Management.

2. Super Admin sees role = admin accounts.

3. Resident accounts are NOT displayed in Admin Management.

4. Barangay Admin cannot access Super Admin Admin Management.

5. Normal resident cannot access Admin Management.

6. Super Admin can use the existing Create Admin functionality.

7. Super Admin can deactivate an Admin.

8. Deactivated Admin cannot log in.

9. Super Admin can reactivate an Admin.

10. Reactivated Admin can log in.

11. Super Admin cannot deactivate itself.

12. Existing barangay records remain after Admin deactivation.

==================================================
IMPLEMENTATION PROCESS
==================================================

Before modifying anything:

1. Inspect User model.

2. Inspect users table/schema.

3. Inspect authentication/login implementation.

4. Inspect existing roles.

5. Inspect existing Admin middleware.

6. Inspect Super Admin middleware.

7. Inspect existing Admin creation functionality.

8. Inspect existing Super Admin routes/controllers/views.

9. Inspect Barangay Admin routes/controllers/views.

10. Inspect existing barangay-scoping logic.

11. Inspect User → Reservation relationships.

12. Inspect payment relationships.

13. Determine whether account activation/deactivation already exists.

14. Identify exactly which files need modification.

Then implement using the SMALLEST necessary changes.

==================================================
DO NOT MODIFY UNRELATED FEATURES
==================================================

Do NOT change unrelated:

- Reservation logic
- Facility Management
- Equipment Management
- Calendar logic
- Payment calculations
- Analytics calculations
- Notification behavior
- PWA functionality

Only make changes required for Admin Management and Resident Management.

==================================================
EXPECTED RESULT
==================================================

SUPER ADMIN

Super Admin Dashboard
        │
        └── Admin Management
                │
                ├── Search/Filter
                ├── View Admin
                ├── Create Admin
                ├── Activate Admin
                └── Deactivate Admin


BARANGAY ADMIN

Admin Dashboard
        │
        └── Resident Management
                │
                ├── Search/Filter
                ├── View Resident
                ├── View Reservation History
                ├── Activate Resident
                └── Deactivate Resident


RESIDENT

No account-management privileges.

==================================================
FINAL REPORT
==================================================

After implementation report:

FILES CREATED:
FILES MODIFIED:
MIGRATIONS CREATED:
ROUTES ADDED:
CONTROLLERS CREATED/MODIFIED:
MIDDLEWARE/POLICIES USED:
TESTS ADDED:

Then explain:

1. How Admin → Resident barangay isolation is enforced.

2. How Super Admin → Admin-only management is enforced.

3. How direct URL/ID manipulation is prevented.

4. How deactivated accounts are blocked from login.

5. How accounts are reactivated.

6. How reservations/payments/history are preserved.

7. What existing functionality was reused.

8. Whether an is_active field was required.

Do not make unrelated changes.