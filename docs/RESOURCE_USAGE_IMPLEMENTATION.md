# Resource Usage Management

Implemented on `feature/resource-usage-management`. The working `ereserve` database has **not** been migrated. The pre-existing deletion of `PAYMENTS_DATATABLE_REPORT.md` is preserved. No Phase 1 integrity migration was changed.

## Inspection and authoritative data

- `facilities` stores both facilities and equipment; `category === 'Equipment'` selects quantity-based behavior. `facilities.total_quantity` remains the only editable inventory master. A null quantity means unverified stock, not zero or an inferred count.
- `reservations.status`, `quantity`, `reservation_date`, `start_time`, and `end_time` remain the booking authority. Only accepted reservations allocate equipment.
- `ReservationAvailability` calculates overlapping accepted allocations, using peak concurrent quantities and half-open intervals. `OfficialUseScheduling` adds active/conflicted official commitments. These calculations are unchanged.
- `ReservationPeriod` supplies schedule interpretation, including next-day end times, in the application's configured timezone (`Asia/Manila`).
- Barangay ownership follows the resource, including bookings by residents from another barangay. Recorded usage also retains its owning barangay and resource ID.
- Existing acceptance, rescheduling, inventory updates, and Official Use workflows lock the facility row. Usage operations follow that same lock order.
- The existing `ReservationActivity`, database notifications, `QueuedReservationMail`, retry middleware, and email template are reused.
- Existing management pages use the shared server-rendered/AJAX DataTable, not a separate DataTables package. The module reuses that implementation, `x-table-actions`, `x-modal`, pagination, badges, forms, CSS, and responsive rules.

## Behavior

The new **Resource Usage** link follows Reservation Management in both desktop and mobile navigation. The page supports all requested record columns, search, sorting on each data column, pagination, scheduled-start date filtering, type/status filters, overdue/outstanding filters, and Super Admin barangay filtering. PDF and Excel exports apply the same authorization and filters.

Accepted reservations appear immediately as **Not Started** or **Not Released**. Viewing, filtering, exporting, and the passage of time never create a usage transaction. Before the first confirmation, Usage Record ID is shown as a dash; the reservation ID remains available. One usage record is created atomically on the first physical action.

| Resource | Confirmed action | Result |
| --- | --- | --- |
| Facility | Check In | In Use; actual start and administrator event recorded |
| Facility | Check Out | Completed; actual end and administrator event recorded |
| Equipment | Release Equipment | Borrowed; released total increases |
| Equipment | Record Return, some units returned | Partially Returned; returned total increases |
| Equipment | All approved units released and returned | Returned; actual end recorded |

Releasing some of an approved quantity is supported. Returning all currently released units while additional approved releases remain pending leaves the record **Partially Returned**, with zero outstanding and no actual end. It is not overdue with zero outstanding. The module does not silently waive unused approved quantities. A cancelled partial release can therefore remain Partially Returned with zero outstanding; there is no administrative “close unused approval” operation in this change.

Every action requires an explicit confirmation, validates resource type, and uses the server timestamp. Check-out requires check-in. A facility still occupied by another reservation cannot be checked in again, even after its scheduled end. Handovers require an accepted reservation, an available resource, sufficient approved quantity, and existing scheduling/Official Use eligibility. Current Official Use commitments are also checked if an administrator hands over equipment outside the reservation's scheduled interval. This does not create physical Official Use loans or subtract those commitments from the displayed physical loan balance.

Returns and check-outs remain permitted after reservation cancellation, resource unavailability, or an Official Use conflict. Neither action modifies reservation status, dates, approved quantities, payments, or refunds. Existing cancellation/refund behavior still runs only through the existing reservation/payment workflows.

## Inventory and concurrency

```text
Outstanding for one usage = released_quantity - returned_quantity
All outstanding loans = sum of outstanding for this facility_id, including cancelled reservations
Physically available = facilities.total_quantity - all outstanding loans
```

The existing schema has no separate damaged/unavailable-unit counter. No second deduction or inventory master was added. Unverified stock is displayed as unverified. The resource's Available/Unavailable status still controls whether new handovers are permitted.

The details and release modals show total inventory, all outstanding loans, physical availability, and scheduled remaining quantity. A warning explains differences between physical and scheduled availability. Returning equipment early frees physical stock but leaves its reservation's scheduled allocation intact.

Transactions acquire locks in resource → reservation → usage order. All releases and returns for the same equipment therefore serialize, including operations for different reservations. Quantity-sensitive reads use locking reads. Facility inventory reductions use the same resource lock and now reject totals below physically outstanding loans, including past/cancelled loans; the existing scheduled-allocation reduction checks remain intact. This guard applies to obligations introduced by this module and does not alter reservation allocation formulas.

A UUID confirmation key is persisted under a unique database constraint. Its payload hash includes reservation, actor, action, quantity, and notes. Retrying the same operation returns success without another event, quantity update, or notification. Reusing its key with a different payload is rejected. Shared modal code also disables controls during submission. A refreshed modal receives a new key for the next intentional transaction.

## Tables and integrity

New migration: `database/migrations/2026_10_22_000001_create_resource_usage_tables.php`.

| Table | Contents | Foreign keys |
| --- | --- | --- |
| `resource_usages` | Unique reservation, resource/tenant/type, approved quantity snapshot, released/returned totals, actual start/end, timestamps | `reservation_id → reservations.id`; `facility_id → facilities.id` |
| `resource_usage_events` | Action, quantity, administrator ID/name snapshot, timestamp, schedule snapshot, notes, unique request key, payload hash | `resource_usage_id → resource_usages.id`; `performed_by → users.id` |

Both tables explicitly use InnoDB on MySQL. All foreign keys restrict deletion, preserving records and administrator attribution. Usage IDs are unique per reservation. Database checks enforce nonnegative quantities, returned ≤ released ≤ approved, resource-specific quantity rules, valid event quantities, and actual end ≥ actual start. SQLite test equivalents are included. Model guards and database triggers reject event updates/deletes; audit history is append-only. Usage status is derived from timestamps and counters rather than maintained as another editable state field. Administrator attribution comes from events rather than duplicated start/end foreign keys.

The migration's `down()` refuses to discard recorded usage. This is not an invitation to run rollback: no rollback or destructive database command was executed.

## Permissions and overdue status

- Barangay Admin: scoped reads/exports, check-in/out, release/return for their resource's barangay.
- Super Admin: all-barangay reads, filters, history and exports; every operational POST is forbidden by the policy/service, regardless of submitted IDs.
- Resident/unauthenticated accounts: no management access. Existing authentication, verification, and account activation middleware still apply.
- History/detail modals are rendered only from the authorized query. Export strings are escaped in PDF and explicitly typed as text in Excel to prevent formulas from names/search input.
- Private usage routes and exports bypass the PWA cache; the worker cache version was advanced accordingly.

Overdue is a separate indicator. A facility is overdue only while checked in beyond its scheduled end. Equipment is overdue only with positive outstanding units beyond expected return. The current reservation schedule remains the expected-time authority after an authorized reschedule; each event preserves the schedule at confirmation. Across-midnight behavior uses `ReservationPeriod` and equivalent database expressions for filtering/sorting. No penalties, charges, or fines are created.

The pre-existing calendar's schedule-derived “In Use” wording remains unchanged to preserve the current calendar workflow. **Resource Usage** is the authoritative record of confirmed physical usage; the calendar label alone does not create or prove a check-in.

## Notifications

Facility check-in/out, equipment releases, and full equipment return dispatch resident notifications through the existing notification class and template. Notification work starts after inventory commits. Email stays on the existing database queue with its retry handling. Duplicate requests do not notify again. A database-notification failure is logged and cannot roll back inventory; unlike a successfully enqueued mail job, a failure before notification persistence is not automatically replayed by this module.

## Validation evidence

| Check | Result |
| --- | --- |
| Complete Laravel suite after initial implementation | 688 tests, 10,017 assertions passed |
| Focused module + equipment + facility suite after inventory-edit/queue coverage | 48 tests, 407 assertions passed |
| Final complete Laravel suite | **690 tests, 10,032 assertions passed; zero failures/errors** |
| Frontend unit tests | 52 passed |
| New module browser checks | 14 combinations passed: Admin and Super Admin at 375, 390, 430, 768, 1024, 1366, 1920px |
| Existing management-table browser checks | 52 page/viewport combinations passed |
| Production build | Passed; existing optional `fontaine` optimization warning remains |
| PHP formatting | New PHP files formatted with Pint |

Browser checks use real headless Chrome with isolated rendered fixtures. They check page overflow, contained horizontal table scrolling, column counts/alignment, both navigation layouts, role-specific controls, floating action menus, compact nonwrapping button labels, modal bounds, focus placement, shared footer buttons, close behavior, and stable retry keys. Shared AJAX tests cover double submission, validation errors, successful refresh, and a committed mutation followed by a failed refresh. Existing management-table checks confirm existing pages retain their layout and action-menu styling. These are automated Chrome checks, not a claim of manual testing on physical mobile devices or other browsers.

MySQL validation used a newly created disposable database, `ereserve_usage_test_20261022093527_70131e44`. Its database-scoped account was verified unable to access the working `ereserve` database. All migrations ran there successfully. Ten checks passed, including simultaneous releases across reservations, duplicate return retries, distinct over-returns, simultaneous release/return, InnoDB engines, database constraints/history immutability, and the MySQL overdue query. Synthetic test data and the isolated account are retained for review, not removed. Results: `storage/app/usage_mysql_20261022093527_70131e44/results.json`; local connection credentials in that ignored artifact directory must not be committed.

The initial pre-edit Laravel run was obstructed by sandbox filesystem access and is not a usable baseline. Once run outside that sandbox, 667 existing tests passed and two navigation assertions failed because they intentionally expected the old navigation list. Those expectations were updated only to include Resource Usage. A frontend test exposed missing private-route coverage for the new URL; that regression was fixed. No outstanding regression was found by the passing suites. Test-fixture column counting/menu reopening and browser timer waits were corrected during UI validation; no production layout workaround was needed.

## Files

New implementation:

- `app/Http/Controllers/ResourceUsageController.php`
- `app/Models/ResourceUsage.php`, `app/Models/ResourceUsageEvent.php`
- `app/Policies/ResourceUsagePolicy.php`
- `app/Services/ResourceUsageManagement.php`
- `app/Support/ResourceUsageQuery.php`, `ResourceUsageReport.php`, `ResourceUsagePresentation.php`
- `database/migrations/2026_10_22_000001_create_resource_usage_tables.php`
- `resources/views/shared/resource-usage/index.blade.php`, `dialogs.blade.php`, `inventory.blade.php`, `report.blade.php`

Existing integration points modified:

- `app/Models/Reservation.php`: usage relationship.
- `app/Support/FacilityCatalog.php`: inventory-reduction guard for outstanding loans.
- `app/Notifications/ReservationActivity.php`: four usage event titles/messages.
- `routes/web.php`, `bootstrap/app.php`: management routes and JSON validation responses.
- `resources/views/partials/user-navigation.blade.php`: one Resource Usage item.
- `public/css/app.css`, `public/css/management-tables.css`: opt the new table into existing rules; no new CSS design system.
- `public/js/reservation-datatable.js`: opt the new page/filter/export into shared behavior; no parallel JavaScript implementation.
- `public/sw.js`: new private namespace and cache version.

Tests/documentation:

- New `tests/Feature/ResourceUsageTest.php` (21 focused tests).
- New `tests/Integration/resource-usage-mysql.php`.
- New `tests/Frontend/resource-usage-fixtures.php`, `resource-usage-browser.cjs`.
- Updated navigation expectations in `tests/Feature/AdminAnalyticsTest.php`, `MobileNavigationTest.php`.
- Updated cache-version expectation in `tests/Frontend/pwa-private-pages.test.cjs`.
- Extended `tests/Frontend/reservation-datatable.test.cjs` with Resource Usage behavior coverage.
- This report, `docs/RESOURCE_USAGE_IMPLEMENTATION.md`.

## Safe migration and activation

No command in this section has been run against the working database.

1. Review the new migration and take a verified database backup. Confirm the existing Phase 1 repairs are already applied and parent tables use compatible InnoDB keys. The migration account needs table/constraint and trigger privileges; enforced CHECK constraints must be supported.
2. Review the SQL without applying it:

   ```powershell
   php artisan migrate --pretend --path=database/migrations/2026_10_22_000001_create_resource_usage_tables.php
   ```

3. Only after explicit approval, apply this single migration during the deployment window:

   ```powershell
   php artisan migrate --path=database/migrations/2026_10_22_000001_create_resource_usage_tables.php
   ```

4. Deploy the code/assets together with the schema. The new page and the usage-aware inventory guard require these tables. Use the existing database queue worker for email delivery; there is no new scheduler or automatic usage-start task.
5. Verify Admin and Super Admin access, one controlled check-in/out, and a small equipment release/return. Reconcile any equipment already physically out on loan before operational rollout: accepted historical reservations are deliberately not assumed to have been released.

Operational boundaries: exports are limited to 5,000 filtered records; date filters use scheduled starts. Legacy null approved quantities/inventory require a separately reviewed reconciliation, not invented values or automatic data backfills. Official Use retains its existing scheduling workflow; this change does not invent an Official Use physical-loan ledger. No correction/delete interface is supplied for immutable history. The working database migration remains an explicit approval/deployment step.
