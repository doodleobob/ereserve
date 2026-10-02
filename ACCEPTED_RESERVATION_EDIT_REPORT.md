# Accepted reservation editing

Accepted reservations retain View/Edit in the row menu. Edit opens one modal with Reschedule Reservation and Cancel Reservation choices, Back buttons, and no separate management page. Pending and historical row actions remain unchanged.

Rescheduling validates the date, times, resource availability, and accepted reservation overlaps through the shared availability and period helpers. The current reservation is excluded; adjacent boundaries, overnight reservations, and scoped legacy resources use the same rules as the calendar. Pending requests do not block availability. A resource lock serializes rescheduling with acceptance. Status remains Accepted.

Cancellation requires explicit confirmation and one of User Requested Cancellation, Official Use, or Other. Notes are optional. Cancellation preserves the reservation and its original schedule, records the reason, notes, timestamp and actor, changes status to Cancelled, and sends the resident a database notification through ReservationActivity. Cancelled rows show View only. Official Use is a reason label; no Official Use scheduling workflow was added.

The current project stores recorded payment in reservations.total_payment and has no separate Payment model or refund workflow. Both operations preserve that amount and the hourly rate snapshot, ignore submitted payment changes, and create no duplicate financial records or automatic refunds. The existing payment endpoint remains available for its established callers.

The additive migration 2026_10_02_000001_add_reservation_change_history was applied to the configured local database. It adds cancellation metadata and JSON change history to reservations; it creates no parallel reservation or notification tables. Change History and cancellation details appear in the admin View modal. Existing rows retain null metadata until changed.

Both operations use the existing authenticated, barangay-scoped PATCH edit endpoint and AJAX transport. Hidden steps are disabled so only active fields are validated/submitted. Closing the modal resets its state. Successful operations refresh the current filtered and paginated table and display a toast. Cancellation reports "Reservation cancelled successfully." The transport reads the action attribute directly to avoid the action input shadowing the form URL property.

Validation completed:

- Full Laravel suite: 193 tests, 2,476 assertions, passed.
- Frontend suite: 19 tests, passed.
- Chrome: 28 scenarios at 1280, 768, 390 and 320 pixels, passed. Includes action menus, choice/Back/reset behavior, inactive field exclusion, cancellation confirmation, both mutations, same-page refresh, toast, and dialog bounds.
- Pint and git diff --check passed.

The full suite runs against isolated SQLite test storage. Existing application reservation records were not edited during verification; the local schema migration was the only application database change.
