# Resident My Reservations presentation

## Changes

The `/reservations` resident cards now display a resource-type badge, barangay, reservation number, and the existing reservation status badge. The information grid uses consistent labels and values, with a separate action row.

- **Purpose:** the original value appears under a clear Purpose label, wraps fully, and preserves line breaks and Blade escaping. `XXX` and `0` remain legitimate values. Missing/blank purposes display “Not recorded” without writing to the record.
- **Quantity:** equipment cards show the reservation's stored `quantity`, with singular/plural units. A historical null quantity displays “Not recorded (legacy booking)”; current stock is never substituted. Facility cards show stored attendees.
- **Classification:** the reservation's stored category identifies the booked resource type, with the resolved resource category as a fallback if the snapshot is missing. Resource names never determine type.
- **Schedule:** same-day cards show Date and Time. Overnight cards show Start and End with both full dates. Duration uses the existing reservation-period calculation, preserving historical minutes and the configured application timezone.
- **Payments:** totals use the authoritative stored reservation total, as existing payment reporting does, without recalculation. “Total Paid” requires a paid payment record; other totals use “Total Payment.” Applicable payment and refund statuses now appear on cards as well as in View Details, including refunds for cancelled or returned-to-pending reservations. An inconsistent old paid record on a pending request is not presented as a new collected payment.
- **Links:** the existing named route helper continues to select `equipment.show` or `facilities.show` for accessible resources. Missing resources retain View Details without a broken resource link.
- **Sorting:** the visible option is “Resource Name.” The existing `facility` query value and sorting behavior are preserved, along with search, status/date sorting, and resident ownership scoping. The resident listing remains unpaginated.
- **Layout:** balanced columns on desktop, two columns at intermediate widths, and stacked details on small screens. Long text wraps; actions retain at least 44px touch targets.

View Details and edit interaction handlers, reservation status colors, global navigation, mobile sidebar, and PWA code were retained. No controllers, models, queries, database records, migrations, booking rules, or payment workflows were changed for this task.

## Files modified

Application:

- `resources/views/reservations/resident-card.blade.php`
- `resources/views/reservations/index.blade.php`
- `public/css/resident-reservations.css`

Tests and documentation:

- `tests/Feature/ResidentReservationPresentationTest.php` (new)
- `tests/Feature/ResidentReservationListingTest.php`
- `tests/Feature/PendingReservationEditTest.php`
- `tests/Feature/OvernightReservationTest.php`
- `tests/Feature/PaymentNotificationTest.php`
- `tests/Frontend/resident-reservation-presentation-browser.cjs` (new)
- `docs/resident-reservation-presentation.md` (this report)

Existing test assertions were adapted to the labeled definition-list markup. Earlier hourly-booking and resource-URL changes already in the working tree were retained.

## Verification

Tests use isolated in-memory SQLite and fake notifications. Browser checks use rendered Laravel fixtures with the application's actual CSS and interaction scripts.

- Main Laravel selection: **63 tests passed, 1,130 assertions** (`ResidentReservationPresentationTest`, `ResidentReservationListingTest`, `PendingReservationEditTest`, `ResourceDetailRoutesTest`, `ResidentFacilityActionsTest`). Output: `storage/app/resident-presentation-tests.log`.
- Payment and overnight selection: **21 tests passed, 270 assertions** (`OvernightReservationTest`, `PaymentNotificationTest`).
- New presentation tests also passed independently: **7 tests, 129 assertions**, included in the main selection above.
- `node tests/Frontend/resident-reservation-presentation-browser.cjs`: **6 viewports passed** (1440, 1024, 768, 600, 390, and 320px), checking full purpose wrapping, exact overnight details, action/link behavior, filter hooks, dialog focus, and horizontal overflow.
- `node tests/Frontend/resident-reservations-browser.cjs`: **4 viewports passed**, covering existing View Details, pending edits, validation, refresh, filter restoration, and focus behavior.
- PHP syntax check for the new test and `git diff --check`: passed.

Laravel selections were executed with `XDEBUG_MODE=off` using `php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --filter=... --no-progress`. Fixture generation used `RESIDENT_PRESENTATION_RENDER_DIR=storage/app/resident-presentation-check` and `PENDING_EDIT_RENDER_DIR=storage/app/pending-edit-check`.

Desktop and 390px mobile renderings were visually inspected in addition to the automated layout checks. No unresolved issues were found within this task's scope. The entire application test suite, physical-device testing, and production deployment were not performed.
