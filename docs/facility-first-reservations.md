# Facility-first resident reservations

Implemented October 15, 2026.

Residents now start from Facilities, open a dedicated Facility Details page, select a date and time, and complete the existing reservation form below the calendar. The Dashboard shows personal Pending and Accepted counts, up to five upcoming/current requests, Browse Facilities, and View All Schedules. The centralized calendar remains available for comparing schedules.

## Reused components and business rules

- Extracted the existing calendar presentation data into `App\Support\ReservationCalendar`, shared by the centralized calendar and Facility Details.
- Extracted the existing monthly calendar and daily schedule markup into `calendar/availability.blade.php`.
- Reused `ReservationAvailability`, `ReservationPeriod`, `FutureReservationStart`, `OfficialUseScheduling`, `FacilityCatalog`, the facility gallery, the facility reservation form, and the existing time-validation script.
- Preserved minute-level and overnight requests, accepted-booking privacy, cross-barangay browsing, resource access restrictions, and Official Use conflicts.
- Preserved the existing policy allowing competing Pending requests, including requests overlapping accepted reservations. Pending requests do not occupy the calendar.
- Past unbooked daily slots now have a gray Past indicator and cannot start a reservation. Accepted history remains visible. These are presentation states, not new database reservation statuses.
- No database migrations or changes to approval, rejection, payment, refund, or notification workflows. A null-safe payment lookup fixes an existing My Reservations crash when a Pending request has no payment record.

## Routes

No routes were added or removed.

| Existing route | Behavior |
| --- | --- |
| `GET /dashboard` | Resident overview; admin dashboards retain their existing behavior. |
| `GET /dashboard?...` | Legacy schedule selections redirect to the resource details page when resolvable, otherwise to `/calendar`. Resource, barangay, month, date, and time context are retained. |
| `GET /facilities` | Existing cards with one resident View Facility action. |
| `GET /facilities/{slug}` | Resident information, shared calendar, daily schedule, and selected-time reservation form. Adds private/no-store cache headers. |
| `GET /calendar` | Existing barangay/type/resource filters and shared schedule browsing. Resident View Facility links carry valid date/time selections. |
| `POST /facilities/{slug}/reservations` | Existing Pending creation and validation; successful HTML submission returns to Facility Details with its date and confirmation. |

The resident top-level navigation remains Dashboard, Facilities, My Reservations, and Profile.

## Files added

- `app/Support/ReservationCalendar.php`
- `public/css/resident-booking.css`
- `resources/views/calendar/availability.blade.php`
- `resources/views/dashboards/resident.blade.php`
- `tests/Feature/FacilityFirstReservationTest.php`
- `tests/Frontend/facility-first-browser.cjs`
- `docs/facility-first-reservations.md`

## Files modified

- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/FacilityController.php`
- `app/Http/Controllers/ReservationController.php`
- `app/Support/ReservationAvailability.php`
- `public/js/reservation-time-validation.js`
- `resources/views/calendar/index.blade.php`
- `resources/views/facilities/index.blade.php`
- `resources/views/facilities/show.blade.php`
- `resources/views/facilities/partials/facility-reservation-form.blade.php`
- `resources/views/reservations/resident-card.blade.php`
- `routes/web.php`
- `tests/Feature/AllResourcesCalendarTest.php`
- `tests/Feature/CrossBarangayReservationTest.php`
- `tests/Feature/DashboardOverviewTest.php`
- `tests/Feature/FacilityManagementTest.php`
- `tests/Feature/FrontendAssetContractsTest.php`
- `tests/Feature/FutureReservationStartTest.php`
- `tests/Feature/LoginDestinationTest.php`
- `tests/Feature/OvernightReservationTest.php`
- `tests/Feature/ReservationCalendarTest.php`
- `tests/Feature/ResidentFacilityActionsTest.php`
- `tests/Frontend/calendar-overview-browser.cjs`
- `tests/Frontend/facility-view-browser.cjs`
- `tests/Frontend/reservation-time-validation-browser.cjs`
- `tests/Frontend/resident-facility-actions-browser.cjs`

Existing tests were updated for the new page locations. The old resident modal browser entry point delegates to the new page-flow checks. Two stale asset-contract expectations were updated to include table assets already present in the unchanged shared layout.

## Verification

- Full PHPUnit suite: **541 passed, 7,310 assertions**.
- Focused facility-first suite: **9 passed**, covering personal counts, legacy redirects, fixed-resource calendars, history and past-time restrictions, custom overnight submission, Pending creation, existing admin approval, cross-month Official Use conflicts, centralized-calendar handoff, validation recovery, and page rendering.
- JavaScript tests: **28 passed** across time validation, calendar filters, cache behavior, and private-page PWA handling.
- Headless Chrome: **52 scenarios passed**: 20 resident page/viewport scenarios, 20 centralized calendar/filter scenarios, and 12 time-validation form/viewport scenarios. Resident pages tested at 320, 390, 768, 1024, and 1440 pixels.
- Laravel Pint completed; `git diff --check` passed.

The full suite runs with `php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --no-progress`. Browser fixtures use isolated test records; set `FACILITY_FIRST_RENDER_DIR=storage/app/facility-first-check` when running the focused suite, then run `node tests/Frontend/facility-first-browser.cjs`.

## Remaining issues and verification limits

No known remaining issues in the requested flow. Database tests use isolated SQLite, and browser checks use rendered Blade fixtures in headless Chrome. Production MySQL, live email delivery, and physical mobile devices were not exercised. No production records or schema were changed.
