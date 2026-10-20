# Fixed hourly booking implementation report

Implemented on October 20, 2026.

## Behavior

Residents select a date, start hour, and end hour. One selection reserves the entire consecutive interval in one reservation record. New selections require whole-hour boundaries and at least one hour; there is no added maximum-duration setting.

The shared selectors are used in facility and equipment booking, the embedded availability calendar, pending-request editing, accepted-reservation rescheduling, and Official Use creation/editing for existing authorized admin roles. The original blue/white styling and compact mobile calendar are retained. Selected duration updates immediately. Availability, available equipment quantity, and price estimates refresh from the server. Stale responses cannot overwrite a newer selection.

New overnight selections require `end_next_day=1` (the **Next day** choice). Earlier end times are rejected without it. Historical overnight records still use their existing stored interpretation. The existing data model supports same-day bookings and overnight bookings ending at an earlier clock time the following day. It has no separate stored end date and did not support equal-clock 24-hour or longer bookings; this change preserves that restriction without a schema migration or new permissions.

Inspection found no configurable operating-hour fields or operating-hour validation. The old eight daytime calendar slots were shortcuts, not booking restrictions. The calendar now evaluates all 24 permitted hourly intervals, including noon and evening hours, and excludes elapsed start times. A daytime booking therefore does not hide remaining evening availability. Adding operating-hour configuration would be a separate feature.

## Validation and availability

`HourlyBooking` centralizes date/time syntax, whole-hour boundaries, future starts in Asia/Manila, explicit overnight intent, and preservation of unchanged historical schedules. Controllers retain resource authorization, tenant isolation, attendee capacity, stock quantity, and resource-status checks. A time/date change requires the new hourly policy. An unrelated edit retains original minute and second values exactly.

Facility bookings use precise half-open intervals: an accepted reservation blocks its complete period, and a reservation may start exactly when it ends. New requests and pending edits now reject overlap with accepted occupancy. Pending requests themselves do not block availability. Acceptance still checks availability under the existing resource row lock, so two competing pending requests cannot both become accepted if they conflict.

Equipment availability continues to use `ReservationAvailability::peakAllocation`, including Official Use quantities. Disjoint reservations are not incorrectly added together across a longer requested interval. NULL quantities retain their legacy whole-resource meaning, including pending edits and availability previews. New equipment requests still require verified stock and a positive quantity. The existing lock order and acceptance transaction are retained.

New Official Use schedules use the same hourly policy. Existing priority handling, pending-request cancellation, accepted-reservation conflicts, resident decisions, notifications, and refund handling remain in the existing scheduling/payment services. Historical minute-based Official Use remains readable and participates in overlap checks.

## Pricing and historical compatibility

The authoritative pricing method is `Reservation::calculatedAmount()`: snapshotted hourly rate multiplied by elapsed duration, rounded using the existing integer-cent arithmetic. Equipment pricing is **per reservation-hour, independent of quantity**, not per unit. For example, 20 chairs at PHP 50/hour for four hours still estimate PHP 200.

The availability endpoint calls that method for estimates; the browser formats the returned monetary value instead of calculating its own price. Pending edits use the stored rate snapshot. Acceptance retains the existing admin-confirmed amount/payment workflow. Rescheduling does not reprice stored paid totals, create duplicate payments, or modify refunds.

No application migrations, schema changes, data backfills, package installations, build-tool changes, or historical-record rewrites were performed. The existing PWA private-route handling remains applicable to the expanded availability endpoint.

## Verification actually executed

| Check | Actual result |
| --- | --- |
| Full Laravel/PHPUnit suite | 638 passed; 9,220 assertions |
| Final focused run after legacy preview and resource-transfer corrections, including two additional tests | 52 passed; 505 assertions |
| Frontend Node unit checks (`tests/Frontend/*.test.cjs`) | 51 passed |
| Hourly form browser checks | 20 passed: five workflows at 320, 375, 412, and 1280 CSS pixels |
| Existing equipment/calendar browser checks | 112 passed: 16 scenarios at seven viewport widths |
| Existing facility-page browser checks | 20 passed: four pages at five viewport widths |
| MySQL equipment migration/acceptance concurrency checks | 11 passed |
| MySQL refund enum/integrity checks | 30 passed |
| Laravel Pint on changed PHP files | Passed |
| `git diff --check` | Passed |

The full-suite run preceded the last two added preview tests; the final focused run covered the affected hourly, equipment, and Official Use code. The counts above are separate runs, not a claim of 640 tests executed together.

PHPUnit enforced in-memory SQLite and array mail; new tests fake notifications. The explicit MySQL runner created a randomly named disposable database, checked child-process database isolation, ran the existing concurrency/refund probes, and removed that database afterward. Application records were not used. No real emails were sent.

Browser checks ran in installed headless Chrome against rendered test fixtures with mocked availability requests. They cover touch target sizing, horizontal overflow, compact calendar indicators, hourly options, quantity shortages, server price display, explicit overnight selection, legacy editing, stale responses, and expired starts. Physical iPhone/Android devices and Safari were not tested.

Results are available locally under `storage/logs/hourly-*.json`, `storage/logs/hourly-*.txt`, and `storage/app/hourly-check/results.json`. These generated artifacts are ignored by Git.

Typical repeat commands with the installed PHP runtime/extensions:

```powershell
php vendor/bin/phpunit
node --test tests/Frontend/*.test.cjs
$env:HOURLY_RENDER_DIR='storage/app/hourly-check'
php vendor/bin/phpunit --filter HourlyBookingTest
node tests/Frontend/hourly-booking-browser.cjs
php tests/Integration/hourly-booking-mysql.php
```

The explicit MySQL runner needs CREATE/DROP DATABASE permission and only removes its own newly created database. The actual PHPUnit runs in this environment used PHP 8.3 with `-n` and explicitly enabled the installed mbstring, pdo_sqlite, fileinfo, openssl, GD, and ZIP extensions.

No unresolved implementation failures or approval requests remain. The existing overnight storage restriction and lack of operating-hour configuration are preserved design constraints; real-device/Safari verification remains unperformed.

## Changed files

The following inventory includes implementation, regression tests, integration-test isolation, and this report.

- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/FacilityController.php`
- `app/Http/Controllers/OfficialUseController.php`
- `app/Http/Controllers/PendingReservationController.php`
- `app/Http/Controllers/ReservationController.php`
- `app/Support/HourlyBooking.php`
- `app/Support/OfficialUseScheduling.php`
- `app/Support/ReservationAvailability.php`
- `app/Support/ReservationCalendar.php`
- `docs/hourly-booking.md`
- `public/css/hourly-booking.css`
- `public/js/reservation-time-validation.js`
- `resources/views/calendar/availability.blade.php`
- `resources/views/calendar/equipment-schedule.blade.php`
- `resources/views/components/hourly-summary.blade.php`
- `resources/views/components/hour-select.blade.php`
- `resources/views/facilities/partials/facility-reservation-form.blade.php`
- `resources/views/official-uses/dialogs.blade.php`
- `resources/views/official-uses/index.blade.php`
- `resources/views/partials/reservation-time-validation.blade.php`
- `resources/views/reservations/admin-dialogs.blade.php`
- `resources/views/reservations/resident-card.blade.php`
- `tests/Feature/AcceptedReservationEditTest.php`
- `tests/Feature/CrossBarangayReservationTest.php`
- `tests/Feature/EquipmentCalendarPresentationTest.php`
- `tests/Feature/FacilityCapacityAcceptanceTest.php`
- `tests/Feature/FacilityFirstReservationTest.php`
- `tests/Feature/FacilityManagementTest.php`
- `tests/Feature/FutureReservationStartTest.php`
- `tests/Feature/HourlyBookingTest.php`
- `tests/Feature/OfficialUseEditTest.php`
- `tests/Feature/OfficialUseTest.php`
- `tests/Feature/OvernightReservationTest.php`
- `tests/Feature/PaymentsManagementTest.php`
- `tests/Feature/PendingReservationEditTest.php`
- `tests/Feature/ReservationCalendarTest.php`
- `tests/Feature/ReservationEmailNotificationTest.php`
- `tests/Feature/ResidentFacilityActionsTest.php`
- `tests/Frontend/equipment-calendar-browser.cjs`
- `tests/Frontend/facility-first-browser.cjs`
- `tests/Frontend/hourly-booking-browser.cjs`
- `tests/Frontend/reservation-time-validation.test.cjs`
- `tests/Frontend/reservation-time-validation-browser.cjs`
- `tests/Integration/equipment-acceptance-mysql.php`
- `tests/Integration/hourly-booking-mysql.php`
- `tests/Integration/payment-status-mysql.php`
