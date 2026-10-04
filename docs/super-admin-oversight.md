# Super Admin resident and payment oversight

Implementation report for the requested cross-barangay, read-only oversight.

## 1. Existing role and authorization architecture

`User.role` stores `user` (resident), `admin`, and `super_admin`. Tenancy uses the existing `barangay` string and `Barangays::ALL`; there is no separate tenant ID to introduce. Management routes require authentication and verified email, and active-account middleware remains in place. Controllers already enforce account/payment permissions, while `ReservationPolicy` governs reservation management.

## 2. Existing Resident Management architecture

`ResidentController` and `AdminController` reuse `ManagesAccounts` and the `accounts` Blade components. Account lists use database search/filtering and pagination; View loads existing safe account details and paginated reservation history through the shared modal script. Resident and admin routes remain separate.

## 3. Existing payment architecture

`PaymentController`, `PaymentQuery`, `PaymentReport`, and the payment Blade components already provide server-side filtering, sorting, pagination, inline View/Edit modals, summary totals, and PDF/XLSX/CSV downloads. Payment ownership delegates to reservation/resource barangay scopes. These components were extended rather than duplicated.

## 4. Super Admin Resident Management changes

Super Admin can open `/admin/residents`, inspect residents across barangays, search name/email, filter barangay and Active/Inactive, sort supported columns, paginate, and open the standardized View modal. Apply and Reset reuse the shared filter controls.

## 5. Resident role filtering

The base resident query explicitly requires `role = user`. Neither `admin` nor `super_admin` accounts can enter this list or its details through filters or guessed IDs. Admin Management continues to require `role = admin`.

## 6. Cross-barangay resident visibility

Super Admin's resident query does not impose the viewer's home barangay. Optional barangay filtering narrows that query. Resident history can span managing barangays and now identifies each resource's managing barangay.

## 7. Super Admin resident permissions

Residents have View only. No status form, confirmation modal, credential/identity editor, delete action, or Add Resident action is rendered. Details retain safe existing account fields; authentication data is not rendered. The server rejects activation/deactivation independently of the UI.

## 8. Barangay admin resident permissions

Regular admins retain existing View and status controls. Their base resident query always requires their own barangay. Foreign resident details/status IDs return 404; request filters cannot replace this scope. Existing account activation, session blocking, and login behavior remain covered by regression tests.

## 9. Super Admin payment changes

Super Admin sees View-only payments with nine columns: Payment ID, Reservation ID, Barangay, Resident, Resource, Reservation Date, Total, Payment Status, and Actions. The existing View modal shows Barangay and closes with a single Close action. Edit dialogs/forms are not generated.

## 10. Cross-barangay payment visibility

The existing Super Admin payment scope intentionally includes all managing barangays. Regular admins continue through the original ownership scope. A resident's home barangay does not determine who manages the resource/payment.

## 11. Barangay column and filter

Payments use `reservation.managingBarangay()` for display. Filtering and sorting use `COALESCE(facilities.barangay, reservations.barangay)`: the actual linked facility owns the payment; legacy reservations without a facility use their stored barangay. Filters validate existing barangay names and bind values to SQL. Arbitrary `barangay_id` parameters have no ownership authority.

## 12. Payment read-only enforcement

`PaymentController::update` requires `role = admin` before validation or writes, and rechecks authorization inside its transaction after locking. Super Admin gets HTTP 403 for every payment status, including Paid to Refunded, even with a confirmed receipt, extra forged fields, or a spoofed form method. Payment and reservation snapshots remain unchanged.

## 13. Regular admin payment editing

Own-barangay admins retain payment View/Edit, transition validation, receipt/refund confirmation, audit history, and refunds. Foreign payment IDs return 403. Refund notifications still originate from the existing `PaymentRecords` workflow.

## 14. Backend authorization changes

| Existing endpoint | Super Admin | Owning barangay admin |
| --- | --- | --- |
| GET `/admin/residents` and resident details | Cross-barangay, resident role only | Own-barangay residents |
| PATCH `/admin/residents/{account}/status` | 403 before validation/write | Existing activation/deactivation |
| GET `/payments` and `/payments/export` | Cross-barangay reporting | Own-barangay reporting |
| PATCH `/payments/{payment}` | 403 before validation/write | Existing status workflow |
| PATCH `/reservations/{reservation}/payment` | 403 before validation/write | Existing scoped total correction |
| Admin Management routes | Existing admin management | Forbidden |

The separate reservation payment-total correction endpoint was guarded because it also changes financial data. Resident identity edit/delete endpoints do not exist (405); attempting the admin-status endpoint with a resident ID returns 404. Existing reservation acceptance, rejection, rescheduling, cancellation, and Official Use workflows retain their original permissions and business rules. No broad policy grant was added for Super Admin.

## 15. Exports

All three Super Admin formats include Barangay and respect Search, Barangay, payment Status, From/To, and sorting. Dates filter the payment recorded date, including the entire To day. Reports reuse the same query and include every match regardless of the displayed page. They omit Actions. Regular admin reports retain their existing columns and own-barangay restriction. PDF uses peso formatting; XLSX retains numeric totals; CSV/XLSX retain protections against executing user text as spreadsheet formulas.

All displayed/exported financial totals still use `Reservation.total` (stored `total_payment`), including summary calculations. `Payment.amount` remains a historical snapshot and is not substituted as the source of truth.

## 16. Tenant security and preserved behavior

Scoped base queries are applied before optional filters. Tests cover differing resident/resource barangays, a differing reservation snapshot, legacy resource fallback, forged barangay filters/IDs, and foreign account/payment IDs. Lists remain paginated (residents 15; payments 10/25/50/100). Resident history eager-loads facilities; payments retain eager-loaded reservation/user/facility relationships and export chunks. Existing query-count regression tests verify queries do not grow per payment row.

Reservation and payment statuses remain independent: cancelled/paid is valid; reads and cancellation do not automatically refund. No notification, email transport, queue, analytics metric, or schema implementation was changed. Existing analytics still counts admins and residents separately using their original definitions.

## 17. UI and navigation

Super Admin now has Resident Management and Admin Management as separate navigation items in the established order. Both oversight pages reuse current headers, filters, tables, pagination, empty states, actions, modals, and responsive CSS. Sortable resident headers include accessible sort state and keyboard focus styling. Payment filter changes, sorting, pagination, Reset, and downloads retain the shared DataTable hooks.

## 18. Files created

- `tests/Feature/SuperAdminResidentOversightTest.php`
- `tests/Feature/SuperAdminPaymentOversightTest.php`
- `docs/super-admin-oversight.md`

## 19. Files modified

- Controllers: `app/Http/Controllers/Concerns/ManagesAccounts.php`, `ResidentController.php`, `PaymentController.php`, `ReservationController.php`.
- Support: `app/Support/PaymentQuery.php`, `PaymentReport.php`.
- CSS: `public/css/app.css`.
- Account views: `resources/views/accounts/index.blade.php`, `details.blade.php`, `status-action.blade.php`, `confirmation.blade.php`.
- Navigation: `resources/views/components/layouts/user.blade.php`.
- Payment views: `resources/views/payments/index.blade.php`, `details.blade.php`, `dialogs.blade.php`, `report.blade.php`.
- Existing feature tests: `tests/Feature/AccountManagementTest.php`, `PaymentsManagementTest.php`, `AdminAnalyticsTest.php`, `SuperAdminAnalyticsTest.php`, `CentralizedCalendarTest.php`.
- Browser fixtures/checks: `tests/Frontend/ui-consistency-fixtures.php`, `ui-consistency-browser.cjs`.

## 20. Tests created and updated

The two new feature classes contain 23 tests covering resident role exclusion, cross-barangay listing/details, searches, combined filters, sorting/pagination, safe details, direct/JSON/spoofed mutation rejection, unchanged database snapshots, separate admin management, regular admin permissions, legacy ownership, all export formats, exports beyond the current page, total source, status independence, and silent reads/exports. Existing tests were updated where their former Super Admin permissions/navigation contradicted the requested behavior.

Existing `PaymentNotificationTest` and `ReservationEmailNotificationTest` cover in-app notifications, email content, Paid to Refunded notification once, database queue execution, transaction/rollback behavior, retries, and transport failure handling. Browser coverage includes Super Admin resident AJAX View, read-only payment View, absence of mutation controls, filter fields/buttons, and responsive layouts.

## 21. Complete test results

All database tests and fixture generation use isolated in-memory SQLite. No application/production database migration, reseed, or data reset was performed; no packages were installed.

- New oversight feature tests: **23 passed**, 269 assertions.
- JavaScript tests: **22 passed**.
- Chrome UI/interaction checks: **210 passed** across 35 rendered pages at 1440, 1280, 1024, 768, 390, and 320 pixels.
- Full Laravel regression suite: **307 passed**, 4,086 assertions, 161.864 seconds.
- Whitespace validation: `git diff --check` passed.
- PHP formatting: Pint passed for all modified PHP files.

Backend tests explicitly confirm HTTP **403** for Super Admin resident status changes, payment status changes/refunds, and payment-total corrections. Rejected requests preserve database records. Existing notification, email, queue, analytics, Admin Management, resident management, and authorization regressions pass.
