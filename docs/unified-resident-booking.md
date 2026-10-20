# Unified Resident booking

Resident facility and equipment detail pages now use one editable reservation form beside the availability calendar. The calendar and hourly details occupy the left column; schedule, reservation details, summary, and submission occupy the right. At widths of 900px and below, these columns stack. Existing calendar indicators, safe-area styles, navigation, and the scrollable hourly list remain in place.

Selecting a calendar date or hourly slot updates the form without navigation. Date/month browsing fetches the existing scoped page and replaces only its calendar section; entered reservation details stay in the original form. The selected date remains unchanged while browsing months. Failed calendar refreshes hide stale hourly rows and provide a retry instruction.

The GET transfer form and “Use Selected Time” button are removed. Equipment has one editable quantity input. Bookmarked query parameters still pass through `ReservationCalendar` validation. Old submitted values take precedence after validation errors.

The shared hourly validation script checks complete selections through the existing `facilities.availability` endpoint. Requests are debounced, aborted when superseded, and guarded by both selection key and request identity. Missing, invalid, loading, failed, and unconfirmed selections disable submission. Failed checks offer retry; requests time out after 12 seconds. No selection submits automatically. The form prevents repeated submissions while the existing server transaction and active-reservation check remain authoritative.

The availability route is included in the existing JSON-exception route list so invalid asynchronous requests return useful JSON validation errors. Malformed arrays in flashed form input are not rendered as scalar field values.

“Same day” remains the default. “Next day (overnight)” explicitly advances the summary end date. Existing storage and policy require an earlier end clock time for an overnight reservation; equal times and periods spanning multiple days remain unsupported. No new maximum-duration policy was added. Current resources have no configured operating-hour restrictions; the existing service continues to offer their permitted hours.

Availability and pricing policies are unchanged:

- Equipment uses peak simultaneous allocation over the entire interval, including accepted reservations, active Official Use, historical minute boundaries, and legacy NULL-quantity exclusive holds.
- Facilities remain exclusive, with adjacent intervals permitted and attendee capacity validated on submission.
- Resource and barangay scope, row locks, approval, notifications, and payment workflows remain in their existing services/controllers.
- Estimated totals come from the existing backend pricing calculation. Quantity does not introduce per-unit pricing.
- Existing reservation times, historical payments, and application records are not migrated or rewritten.

Files changed specifically for this task:

- `resources/views/facilities/show.blade.php`
- `resources/views/facilities/partials/facility-reservation-form.blade.php`
- `resources/views/calendar/availability.blade.php`
- `resources/views/calendar/equipment-schedule.blade.php`
- `resources/views/components/hourly-summary.blade.php`
- `public/css/resident-booking.css`
- `public/js/resident-booking.js` (new)
- `public/js/reservation-time-validation.js`
- `app/Support/ReservationCalendar.php` (retain selected-day events when browsing another month)
- `bootstrap/app.php` (JSON errors for the availability endpoint)
- `tests/Feature/UnifiedResidentBookingTest.php` (new)
- `tests/Feature/FacilityFirstReservationTest.php`
- `tests/Feature/EquipmentCalendarPresentationTest.php`
- `tests/Feature/FutureReservationStartTest.php`
- `tests/Feature/ResidentFacilityActionsTest.php`
- `tests/Frontend/unified-resident-booking-browser.cjs` (new)
- `tests/Frontend/facility-first-browser.cjs`
- `tests/Frontend/equipment-calendar-browser.cjs`
- `tests/Frontend/hourly-booking-browser.cjs`
- This report.

The working tree already contained hourly-booking changes before this task. They were retained; the list above identifies this task's additions and updates rather than attributing the entire working tree to this change.

## Verification

PHP feature/unit tests use the existing guarded in-memory SQLite setup and captured/faked mail. Browser checks use rendered Blade fixtures, the actual CSS/JavaScript, and mocked availability/calendar requests in headless Chrome. They cover schedule and quantity changes, same-day/overnight summaries, stale-response ordering, offline retry, preservation of details, repeated-submit prevention, required inputs, and desktop/mobile viewport layout.

The existing MySQL wrapper additionally checks real locking/concurrent acceptance and payment behavior in a uniquely named disposable database; application tables are untouched.

Browser checks are viewport simulations, not physical-device or installed-PWA testing.

Final results:

| Check | Result |
| --- | --- |
| Six affected PHP test classes, including the new unified-booking tests | 86 passed, 858 assertions |
| Email-notification regression class, run in a fresh process | 30 passed, 378 assertions; array mail transport |
| Shared hourly JavaScript unit tests | 11 passed |
| Equipment calendar browser checks | 112 scenarios passed |
| Facility-first page browser checks | 20 scenarios passed |
| Shared hourly form browser checks | 20 scenarios passed |
| New unified booking browser checks | 10 scenarios passed |
| MySQL equipment migration/concurrency integration | 11 checks passed |
| MySQL payment/status integration | 30 checks passed |
| PHP/JavaScript syntax checks and `git diff --check` | Passed |

The broad initial PHP run executed 645 tests: 628 passed, two old hidden-input assertions failed, one new test incorrectly expected JSON from the HTML detail route, and 14 email tests exceeded the queue worker's default 128 MB limit (exit code 12) late in the combined process. The assertions and new test were corrected. All affected booking tests and the entire email class then passed in the fresh runs above. The combined-process memory limit remains a test-harness limitation; production worker configuration was not changed.

Desktop and 390px mobile screenshots were also inspected. The final visual check verified compact form spacing and caught/fixed an existing button display style overriding the retry button's hidden state. Browser assertions now check actual visibility for retry controls and failed-calendar rows.

No known functional issue remains in the requested workflow. Physical-device/PWA testing and a second complete combined PHP run were not performed.
