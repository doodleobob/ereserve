Payments Management is implemented at `/payments`, following `database/specs/dt_payment.md`. The eight-column table supports search, filters, sorting, pagination, same-page View/Edit, filtered summaries, and downloadable PDF/XLSX/CSV reports. Payment edits preserve reservation fields and financial history.

1. **Files created**

   ```text
   app/Http/Controllers/PaymentController.php
   app/Models/Payment.php
   app/Support/PaymentQuery.php
   app/Support/PaymentRecords.php
   app/Support/PaymentReport.php
   database/migrations/2026_10_02_000004_integrate_payment_records.php
   resources/views/payments/index.blade.php
   resources/views/payments/details.blade.php
   resources/views/payments/dialogs.blade.php
   resources/views/payments/report.blade.php
   tests/Feature/PaymentsManagementTest.php
   tests/Frontend/payment-fixtures.php
   tests/Frontend/payment-browser.cjs
   PAYMENTS_DATATABLE_REPORT.md
   ```

2. **Files modified**

   ```text
   app/Http/Controllers/ReservationController.php
   app/Models/Reservation.php
   bootstrap/app.php
   composer.json
   composer.lock
   phpunit.xml
   public/css/app.css
   public/js/reservation-datatable.js
   public/sw.js
   resources/views/components/layouts/user.blade.php
   routes/web.php
   tests/Feature/AdminAnalyticsTest.php
   tests/Feature/OfficialUseTest.php
   tests/Frontend/calendar-cache.test.cjs
   ```

3. **Existing schema discovered.** Checked-in code had no Payment model, payment-table migration, Payments controller, or Payments page. Reservation pricing uses `hourly_rate_snapshot`; `calculatedAmount()` estimates the charge with integer-cent arithmetic and supports overnight periods. Administrators record confirmed receipt in nullable decimal `reservations.total_payment` when accepting, with a separate existing correction endpoint in Reservation Management. There is no physical `reservations.total` column.

   The configured MySQL database already contained one legacy Payment with `id`, unique `reservation_id`, decimal `amount`, enum `payment_status`, `refunded_at`, `refunded_by`, `revision`, JSON `history`, and timestamps. This table used MyISAM. All original columns and values are preserved. Its `amount` remains the legacy snapshot; this page and its reports read the Reservation total.

   The additive migration creates the corresponding Payment table on clean installations, validates an existing table, converts MyISAM to InnoDB for transactions, and adds the status/recorded-date index when missing. It can backfill a missing Paid record for an Accepted reservation with an existing recorded total, including zero. It does not infer receipt for Pending, Cancelled, or unrecorded reservations. Existing payment pairs are skipped. Backfilled records use the available reservation update/create timestamp; this is a historical recording approximation, rather than a fabricated transaction date. Rollback retains financial records. The migration was applied locally and inserted no new records because no confirmed payments were missing.

4. **Statuses discovered.** The existing enum contains Pending, Paid, Refunded, and Cancelled. These same values drive badges, filters, validation, and export labels. Supported changes are Pending to Paid/Cancelled and Paid to Refunded; closed records retain their status. Repeating the current status is idempotent. Recording Paid or Refunded requires confirmation that receipt/refund actually occurred. No new payment status was added.

5. **Relationships.** `Payment::reservation()` belongs to Reservation and `Reservation::payment()` has one Payment. Resident and resource data come from `reservation.user` and `reservation.facility`, with existing reservation resource snapshots as fallback for legacy bookings. Payment records have no Official Use relationship. Current resource ownership determines tenant scope, matching existing reservation management.

6. **DataTable.** Columns are Payment ID, Reservation ID, Resident, Resource, Reservation Date, Total, Payment Status, and Actions. IDs retain the project's `#ID` presentation. Existing table, menu, badge, dialog, toolbar, toast, and responsive styles are reused. Actions offer View/Edit. No financial deletion endpoint exists. Normal page display loads only its paginated records and eager-loaded relationships.

7. **Search.** Parameterized queries search current resident/resource names and legacy resource names. Numeric IDs, `#ID`, `PAY-ID`, and `RES-ID` are supported. Search stays inside tenant scope. Seven whitelisted sort keys cover every data column; Actions is unsortable.

8. **Filters.** Status options use the discovered enum. From/To dates filter `payments.created_at`, the payment recorded date; the page explains the distinction from the scheduled Reservation Date column. Both boundaries include the requested day, and one-sided ranges work. Invalid dates and reversed ranges return validation errors. Empty optional controls use safe defaults.

9. **Pagination.** Laravel server pagination supports 10/25/50/100 rows, with preserved search, status, dates, and sort parameters. When an edit removes the final row of a filtered page, refresh selects the last valid page. AJAX navigation replaces the page's main content and reinitializes dialogs without leaving Payments.

10. **Summary.** Total Payments and Total cover the entire filtered dataset, independently of page size. Total sums reservation values in cents. Unknown totals display “Not recorded” and are counted explicitly rather than substituted from a legacy Payment snapshot. Refunded records retain their original reservation charge; Total represents charges in the selected dataset, not net revenue.

11. **View.** A same-page modal displays payment/reservation IDs, resident, resource, scheduled date, start/end including overnight continuation, Total, and Payment Status. It contains read-only details and a Close action.

12. **Edit.** A same-page modal displays read-only reservation information and permits Payment Status changes supported by the current record. The fetch transport submits PATCH, prevents duplicate submissions, disables controls/Escape while saving, preserves values on validation errors, and refreshes status and filtered totals on success. Toast: “Payment updated successfully.” Closing discards unsaved changes.

13. **Status updates and creation.** `PaymentRecords` records status changes with actor, timestamp, before/after state, and incremented revision. Refunds fill the existing refund timestamp/actor. The update controller locks Reservation then Payment, rechecks scope and transitions, and saves only Payment fields. Submitted financial, reservation, owner, refund-actor, or schedule overrides are ignored. No reservation status or total is changed.

   Existing acceptance now creates one Paid record in the same transaction as the accepted reservation and its notification, or advances an existing Pending payment without duplicating it. Closed historical payments cannot be silently reopened through acceptance. Notification failure rolls back new payment creation. Rescheduling, cancellation, Official Use conflict processing, and Reservation Management total corrections preserve the existing Payment record and snapshot. Cancellation does not record a refund.

14. **PDF.** Dompdf generates a landscape A4 report with dynamic barangay, date range, date basis, selected status/search, and generated time. Its seven report columns exclude Actions. Unicode peso totals, repeating headers, row-preserving pagination, page numbers, filtered count/Total, unknown-total notes, and a valid empty report are included. The template escapes data; remote resources and executable PHP/JavaScript are disabled. Tests decode the generated PDF text streams to verify actual downloaded content, totals, scope, status changes, and multiple pages.

15. **Excel.** PhpSpreadsheet produces genuine `.xlsx` files. Payments contains the seven raw columns, a frozen header and auto-filter, numeric IDs and Total cells, number formatting, and readable column widths. A Report sheet contains filter metadata and filtered count/Total, including numeric zero. Unknown totals stay blank. User-controlled cells and search metadata are explicitly text, preventing formula execution.

16. **CSV.** UTF-8 CSV with a BOM uses the same seven columns, native CSV escaping, and plain numeric decimal totals. User-controlled formula-like values are protected. There are no badges, buttons, Actions, or HTML UI fragments. An empty dataset produces a valid header-only CSV.

17. **Shared query and downloads.** `PaymentQuery` supplies DataTable, summary, and every export. Reports ignore pagination and retrieve matching rows in batches of 500 with eager loading. Report summary and rows are read within one transaction. CSV streams output; Excel/PDF rendering requires memory proportional to the document. These document formats remain synchronous. Filenames follow `ereserve-payment-report-YYYY-MM-DD.ext`. The Download menu fetches a file, validates its response type, downloads through a temporary object URL, handles failures with a toast, and suppresses repeated clicks while generating.

18. **Reservation.total.** A read-only accessor exposes the existing stored `total_payment` as `Reservation.total`. It introduces no duplicate database column and does not rename the existing field. Table, both modals, and reports read this accessor. SQL sorting/sums use its underlying field. Existing dashboard/analytics queries that select counts `AS total` retain their selected aggregate values; a regression test covers this compatibility. The legacy Payment snapshot is preserved without becoming the report's source of truth. Every new Payments surface uses “Total” terminology.

19. **Tenant scope.** DataTable, summaries, update authorization, and every report apply the existing Reservation ownership scope: resource-owning barangay, or reservation barangay for legacy bookings without a facility ID. Client-supplied barangay parameters cannot broaden access. Super Admin has the established global scope. Residents cannot access management or exports, even by direct URL. Private no-store responses and PWA cache bypass include Payments and report routes.

20. **Authorization/security.** Routes retain authenticated, verified, active-session middleware. Controllers independently require Admin/Super Admin; update rechecks the locked record. Backend status/date/format validation, scoped relationship queries, bound search values, whitelisted sorting/formats/templates, escaped output, explicit spreadsheet text, and read-only reservation fields are covered by tests. No Delete action or route was introduced.

21. **Dependencies.** No export package existed. Added `dompdf/dompdf` **3.1.6** and `phpoffice/phpspreadsheet` **5.10.0**, compatible with the installed PHP 8.3 environment, plus their Composer dependencies. Existing libraries were retained. Library references: [Dompdf](https://github.com/dompdf/dompdf), [PhpSpreadsheet documentation](https://phpspreadsheet.readthedocs.io/en/stable/topics/recipes/).

   Composer's advisory audit reports **19 advisories across four pre-existing packages**: `guzzlehttp/guzzle`, `laravel/framework`, `league/commonmark`, and `league/flysystem`. The installed report libraries were not flagged. These existing dependency findings remain for a separate dependency-update task; this change does not upgrade the framework or unrelated packages.

22. **Tests.** Added 20 Payments feature tests covering authorization, current relationships/totals, search, date/status combinations, all page sizes/sorts, malformed parameters, immutable reservation/payment snapshots, transition validation and idempotence, confirmed creation and rollback, reservation/Official Use history preservation, empty/unknown totals, all filtered exports, fresh status after editing, formula/HTML handling, migration retry/history retention, a 502-record chunked CSV, multipage PDF content, query-count stability, and last-page recovery. Added isolated in-memory SQLite fixtures generating HTML and real report files and Chrome checks at 1280/768/390/320 pixels. Extended PWA cache tests. Updated the original Official Use legacy-schema test to recreate its legacy Payment fixture within the isolated test database. Repeated report generation uses renderer cleanup between tests and a test-only 256 MB memory allowance in `phpunit.xml`; the local PHP default is 128 MB.

23. **Verification.** Payments tests within the full suite: **20 passed, 326 assertions**. Frontend unit suite: **21 passed**. Chrome: **four viewport checks passed**. Full Laravel regression suite: **246 passed, 3,362 assertions**. Pint formatting and `git diff --check` passed. Local MySQL smoke checks render the real page and generate all three reports without editing payments. Migration SHA-256 fingerprints confirm all original data remained unchanged: one Payment, two Reservations, seven Users, two Facilities, three Official Uses, two conflicts, and nine notifications. No reset or reseed occurred. One-off audits and report artifacts are under ignored `storage/app/` paths; the supplied specification is unchanged.
