# Overnight reservations and time-field alignment

## Architecture and cause

Reservations already store `reservation_date` as DATE and `start_time`/`end_time` as TIME. These columns are sufficient for the requested interpretation, so no migration or development database changes were needed.

The controller previously required `after:start_time`. Duration subtracted clock minutes, availability matched only the reservation's starting date, and In Use checks assumed the end was on the same date. These independent assumptions rejected or misrepresented overnight bookings.

## Shared datetime logic

`App\Support\ReservationPeriod` resolves both times against the reservation date using the configured application timezone. An end earlier than the start moves forward one calendar day. Equal times remain an invalid zero-length period. Validation checks this resolved period after validating the date and time formats.

`Reservation::period()` exposes the same interpretation to duration, price calculations, occupancy, In Use status, calendar events, admin date filtering, and end-date labels. Duration uses the full datetime difference. Existing integer-cent pricing and admin total confirmation stay in place: ₱50/hour from 22:00 to 00:00 produces 120 minutes and ₱100.00. Pending users still see only the hourly rate; accepted users see the admin-confirmed total.

## Calendar, status, and occupancy

- Accepted-reservation queries include candidates starting the previous day, then filter by full datetime overlap. This also handles carryover into a new month.
- Month cells and daily schedule slots use the same overlap predicate: reservation start is before interval end, and reservation end is after interval start.
- Overnight calendar entries appear on both occupied dates. The starting day shows “Ends …”; the next day shows a midnight continuation with “Continued from …”. A midnight end does not create occupancy on the next day.
- Continuation intervals can be selected through the existing calendar reservation flow.
- In Use means `start <= now < end` for an accepted reservation, including after midnight. Facility cards use this same comparison. At the exact end, the facility is no longer In Use for that booking.
- The existing Booked classification outside the active interval and past-day calendar behavior remain unchanged.
- Manually Unavailable facilities keep that status. Pending/rejected reservations do not block the calendar. Overlapping requests by different residents remain allowed.
- Admin schedule date filtering includes reservations continuing into the selected date. Reservation lists, dashboard tables, current-use facility displays, and newly generated notification details show the overnight end date.

## Form alignment

The time fields were nested grids stretched to equal height; when one column contained an error, implicit row distribution could shift the other column's label/input. The parent now uses equal `minmax(0, 1fr)` columns with `align-items: start`, and each field group uses `align-content: start` and `min-width: 0`.

Both inputs retain the existing 36px height, errors remain below their respective inputs, and the existing breakpoint stacks the columns at 640px and below. No positional offsets were added. A short hint explains the next-day interpretation and equal-time restriction.

## Tests

- Full Laravel suite: **101 tests passed, 982 assertions**.
- The 12 new overnight cases cover all seven requested valid ranges; equal times; validation markup; overnight payment; both calendar dates; continuation selection; overlapping requests; midnight In Use and end boundaries; manual unavailability; month carryover; barangay filtering; accepted totals and notification end dates.
- Existing notification JavaScript suite: **4 tests passed**.
- Fixed the existing calendar fixture helper name so Pint does not rename a helper beginning with `test` without updating its caller.
- `git diff --check` and JavaScript syntax checks passed. Changed PHP was formatted with Laravel Pint.
- Tests use the existing enforced SQLite `:memory:` guard. No development database reset or migration was run for this task.
- `tests/Frontend/time-layout.cjs` provides headless browser measurements at 1100px, 768px, and 390px, with no error, a start error, and an end error. Both Chrome and Edge timed out before returning results in this environment. Browser alignment verification remains incomplete; the grid rules and rendered error placement were checked, but no successful browser measurements are claimed.

## Files changed for this request

Created:

- `app/Support/ReservationPeriod.php`
- `tests/Feature/OvernightReservationTest.php`
- `tests/Frontend/time-layout.cjs`
- `specs/overnight_reservation_report.md`

Modified:

- `app/Models/Reservation.php`
- `app/Http/Controllers/ReservationController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Support/ReservationAvailability.php`
- `app/Support/FacilityCatalog.php`
- `app/Notifications/ReservationActivity.php`
- `resources/views/facility-show.blade.php`
- `resources/views/facilities.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/reservations/index.blade.php`
- `public/css/app.css`
- `tests/Feature/ReservationCalendarTest.php`

Earlier payment/notification changes remain in the working tree, including the blue notification buttons.
