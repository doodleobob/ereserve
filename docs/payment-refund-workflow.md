# Payment cancellation and refund workflow

The MySQL error 1265 occurred because application code supported `refund_pending` before the enum migration had been applied. On October 15, 2026, the live `ereserve` database still had `enum('pending','paid','refunded','cancelled')`; the refund migration was pending.

The new migration `2026_10_14_000001_add_refund_pending_to_payments.php` was completed while still unexecuted, then applied as batch 2. No previously executed migration was edited. Its MySQL implementation appends `refund_pending` while preserving every existing enum literal and its order, the full column attributes, and all rows. It preserves the actual installed definition rather than assuming a default or nullability.

The deployed column is now `enum('pending','paid','refunded','cancelled','refund_pending')`, still NOT NULL with default `pending` and collation `utf8mb4_unicode_ci`. Payments and reservations both use InnoDB.

## Record audit

The error logs identify Payment #3, linked to Reservation #3. Before applying the migration, this reservation remained accepted and the payment remained paid. Its cancellation timestamp was null, payment revision was zero, and neither payment history nor reservation change history contained a cancellation. This is consistent with rollback of the failed transaction. The admin should retry the cancellation; no historical correction was performed.

All six payments and six reservations were inspected. Five pairs were accepted/paid and one was cancelled/refunded. No inconsistent status pairs, invalid payment statuses, or incomplete refund tracking were found. Full-row hashes matched before and after the migration:

- Payments: `539e0baec2f5d952af244c3856813ba7d2c5c0233205fad30a6f66e88edfc0a0`
- Reservations: `552ab4d9817d951b35ac76b930ab79f344d04ded0b662e93a5a3a1fccd730476`

## Behavior and changed files

- `app/Models/Payment.php`: five supported statuses, readable status labels, and the `paid -> refund_pending -> refunded` transition chain. Existing pending-to-paid/cancelled transitions and terminal-status idempotency are retained.
- `app/Support/PaymentRecords.php` and `app/Http/Controllers/ReservationController.php`: cancellation locks and changes the payment within the reservation transaction. Pending or missing receipts close as cancelled; paid receipts become refund pending. Amounts and prior history are retained. Refund timestamps and actor tracking are written only on confirmation through the existing PaymentController authorization and confirmation checks.
- `resources/views/payments/index.blade.php`, `resources/views/payments/dialogs.blade.php`, `resources/views/reservations/resident-card.blade.php`, and `public/css/app.css`: Refund Pending labels, the existing pending-status color, and a Confirm Refund dialog requiring explicit confirmation. View is available for every payment status; Confirm Refund is available only for refund-pending payments managed by an authorized barangay admin. The Edit action and modal have been removed.
- `public/js/payment-actions.js`: compact payment action dropdowns that leave table row sizes unchanged, fit within the viewport, close on outside click/Escape/scroll, and return focus to the three-dot trigger after a modal closes. The existing `payments.update` route remains because Confirm Refund uses its transaction, authorization, and confirmation checks.
- `app/Support/PaymentReport.php`: readable labels and filters in CSV, Excel, and PDF exports.
- `app/Support/AdminAnalytics.php` and `app/Support/SuperAdminAnalytics.php`: separate pending and completed refund aggregates. Known unpaid/refund payments are excluded from collected revenue, including payments attached to accepted reservations. Existing legacy accepted totals without payment records are preserved.
- `tests/Feature/PaymentsManagementTest.php` and `tests/Feature/ReservationEmailNotificationTest.php`: cancellation, confirmation, history, repeat requests, invalid transitions, tenant authorization, transaction rollback, reports, analytics, and migration preservation/rollback checks. Cancellation notification behavior is unchanged; refund notification occurs only after confirmation.
- `tests/Integration/payment-status-mysql.php`: actual MySQL enum changes and rollback on uniquely prefixed scratch tables. Application payment/reservation records are never used as fixtures.
- `tests/Frontend/payment-fixtures.php` and `tests/Frontend/payment-browser.cjs`: browser checks for the pending status and confirmation flow.

## Validation

All 120 relevant PHP tests passed (2,009 assertions), along with 30 MySQL integration checks and browser checks at four viewport sizes. Pint passed for the migration and PHP files changed in the enum repair; `git diff --check` passed.

```powershell
php -d xdebug.mode=off vendor/bin/phpunit --filter 'PaymentsManagementTest|AcceptedReservationEditTest|ReservationEmailNotificationTest|SuperAdminPaymentOversightTest|AdminAnalyticsTest|SuperAdminAnalyticsTest|ResidentReservationListingTest'
php tests/Integration/payment-status-mysql.php
php tests/Frontend/payment-fixtures.php
node tests/Frontend/payment-browser.cjs
```

## Rollback

The migration removes only `refund_pending` when no payment uses it and it is not the column default. If either condition prevents rollback, it throws before changing the schema or payment data. Resolve genuine outstanding refunds through the confirmation workflow; do not relabel payments solely to permit rollback.

Stop payment writers during schema rollback and deploy compatible application code with the schema change. Removing the enum value while leaving code that writes it would reintroduce error 1265. Existing paid/refunded/cancelled/pending records, financial amounts, histories, column defaults, and nullability are preserved by a successful rollback.
