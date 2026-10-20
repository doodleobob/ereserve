# Separate facility and equipment detail URLs

Both resource types still use the existing `Facility` model, `FacilityController`, detail Blade view, and booking components. Resource classification comes from the stored `category`; a URL or query parameter cannot override it.

## Routes and redirects

| Request | Behavior |
| --- | --- |
| `GET /facilities/{slug}` (`facilities.show`) | Render a visible Facility resource |
| `GET /equipment/{slug}` (`equipment.show`, new) | Render a visible Equipment resource |
| `/facilities/chairs` when Chairs is Equipment | 301 to `/equipment/chairs` |
| `/equipment/covered-court` when Covered Court is Facility | 301 to `/facilities/covered-court` |
| Missing resource or resource inaccessible to the current role | 404, without a redirect destination |

The controller resolves the resource through `FacilityCatalog::findForUser` before redirecting. Slugs are unique in the existing schema, making the mapping unambiguous. Redirects retain all query parameters, including date, month, start/end hours, overnight selection, quantity, and extra parameters. Query parameters are appended separately so even a query parameter named `slug` cannot replace the destination resource. Flash data is retained for validation redirects from an old bookmarked URL. Detail responses and redirects retain private/no-store cache headers.

Existing permissions are preserved: residents may browse other barangays under the current catalog policy, but cannot book another barangay's residents-only resource. Barangay admins cannot view resources outside their scope; super admins retain their existing visibility.

## Updated links

- Resident facility and equipment listing cards.
- My Reservations resource links, with **View Equipment** for equipment.
- Legacy Dashboard booking links.
- Super Admin recent-resource activity.
- Embedded calendar date, month, and hourly-slot links.
- Successful reservation submission redirects.
- Equipment reservation cancellation link back to the Equipment listing.

All detail links use named routes selected by one `FacilityCatalog::detailRoute` helper. All Schedules and Official Use were inspected: their existing schedule displays do not contain resource-detail links requiring replacement. Notifications and emails link to reservation details, so those destinations remain unchanged.

Booking POST, availability GET, and resource-management endpoints retain their existing named routes and controllers. No booking, pricing, payment, Official Use, or notification business rules were changed. No resource records, IDs, slugs, historical records, or database schema were changed.

## Files modified for this task

Application:

- `routes/web.php`
- `app/Support/FacilityCatalog.php`
- `app/Http/Controllers/FacilityController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/ReservationController.php`
- `app/Support/ReservationCalendar.php`
- `app/Support/DashboardOverview.php`
- `resources/views/facilities/index.blade.php`
- `resources/views/facilities/partials/facility-reservation-form.blade.php`
- `resources/views/reservations/resident-card.blade.php`

Tests and documentation:

- `tests/Feature/ResourceDetailRoutesTest.php` (new)
- `tests/Feature/EquipmentCalendarPresentationTest.php`
- `tests/Feature/UnifiedResidentBookingTest.php`
- `tests/Feature/HourlyBookingTest.php`
- `tests/Feature/EquipmentQuantityTest.php`
- `tests/Feature/FacilityViewTest.php`
- `tests/Feature/ResourceSeparationTest.php`
- `tests/Feature/CrossBarangayReservationTest.php`
- `tests/Feature/ResidentFacilityActionsTest.php`
- `tests/Frontend/unified-resident-booking-browser.cjs`
- `tests/Frontend/calendar-cache.test.cjs`
- This report.

The prior hourly/unified-booking work and other existing working-tree changes were retained.

## Verification

The Laravel regression selection covers `ResourceDetailRoutesTest`, `ResourceSeparationTest`, `EquipmentQuantityTest`, `EquipmentCalendarPresentationTest`, `UnifiedResidentBookingTest`, `HourlyBookingTest`, `FacilityViewTest`, `ResidentFacilityActionsTest`, `CrossBarangayReservationTest`, `ResidentReservationListingTest`, and `FacilityFirstReservationTest`. Tests use guarded in-memory SQLite, with notifications faked; the application database was not reset or modified.

Final Laravel result: **115 tests passed, 1,683 assertions, 49.69 seconds**. Executed with `XDEBUG_MODE=off` and `php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --filter='ResourceDetailRoutesTest|ResourceSeparationTest|EquipmentQuantityTest|EquipmentCalendarPresentationTest|UnifiedResidentBookingTest|HourlyBookingTest|FacilityViewTest|ResidentFacilityActionsTest|CrossBarangayReservationTest|ResidentReservationListingTest|FacilityFirstReservationTest' --no-progress`. Output is recorded in `storage/app/resource-detail-route-final.log`.

The nine new route tests cover canonical pages, both redirect directions, query preservation (including nested parameters and a query named `slug`), missing resources, role scoping, existing cross-barangay access rules, updated links, booking submission/pricing, and validation errors/input surviving the old-URL redirect.

Browser and static checks:

- `node tests/Frontend/unified-resident-booking-browser.cjs`: **10 scenarios passed**, including correct detail and Cancel links across desktop/mobile widths.
- `node tests/Frontend/hourly-booking-browser.cjs`: **20 form/viewport scenarios passed**.
- `node --test tests/Frontend/calendar-cache.test.cjs`: **1 test passed**, including equipment detail URLs and offline behavior.
- PHP syntax checks and `git diff --check`: passed.
- Route inspection confirmed the new named `equipment.show` route.

Desktop/mobile checks use headless Chrome with rendered Laravel fixtures and the application's scripts/styles. Physical-device testing and production deployment were not performed.

No remaining issues were found within this task's scope. The complete application test suite was not rerun for this route change.
