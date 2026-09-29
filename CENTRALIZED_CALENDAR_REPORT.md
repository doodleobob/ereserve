# Centralized calendar implementation

The calendar is shared; reservation management remains scoped to the resource owner.

## Root cause

The previous cross-barangay change already selected the resource owner's barangay for the user calendar, but the availability service still filtered the reservation's stored barangay. A linked resource with a stale reservation barangay could therefore disappear from availability. Resource selection also used the role-scoped catalog, and administrators were directed to their operations dashboard without a shared calendar route.

A separate source of the reported expectation was reservation status: the application intentionally counts only accepted reservations. As confirmed by the user, this rule is preserved. A new pending request does not mark a day Partially Booked; acceptance does.

## Shared source and filters

`ReservationAvailability::acceptedReservations()` resolves the resource and reads its accepted reservations by facility ID, date, and time. An optional barangay filter checks the resource owner rather than the reservation's snapshot or reserving user's home barangay. Legacy records without a facility ID retain the existing safe slug-and-owner fallback.

Month availability, day slots, occupied-time events, and the catalog's Currently in Use indicator reuse this source. Facilities and equipment both use the existing Facility model and the same calculations. Overnight handling, unavailable resources, and existing slot definitions are preserved.

Users retain their calendar dashboard. `/calendar` exposes the same calendar to all authenticated, verified, active accounts; administrators retain their existing operations dashboard and gain a Calendar navigation link.

The barangay dropdown defaults to the home barangay, or the owner of an explicitly linked resource. All Barangays exposes resources across the system. The Resource dropdown groups facilities and equipment and requires one explicit selection. Without a valid selection, the page shows a placeholder and no calendar. Changing barangay clears the resource; incompatible URL selections also show the empty state. All Barangays remains a barangay filter, but never displays multiple calendars. The View Calendar filter button and all-resource option have been removed. Navigation preserves the filter. Filters never update an account, resource, or reservation.

Calendar responses use private/no-store headers. The service worker fetches calendars from the network and shows the offline page when unavailable instead of replaying a stale calendar.

## Authorization and privacy

Calendar visibility does not authorize management. The existing ReservationPolicy and management queries still restrict barangay admins to the resource owner; super admins retain system-wide management. Resident-directory scoping and residents-only booking enforcement remain intact.

Shared calendar events contain only availability labels, time boundaries, and overnight notes. They do not include requester names, contact details, purpose, payment details, or identity documents. No tables, migrations, or duplicate reservation records were added for this change.

## Files changed for this request

- `app/Support/ReservationAvailability.php`: resource-based query and reusable event projection.
- `app/Support/FacilityCatalog.php`: shared read-only calendar catalog and unified current-use lookup.
- `app/Http/Controllers/DashboardController.php`: shared calendar handler and validated filters; existing admin dashboard preserved.
- `routes/web.php`: shared calendar route and cache-control middleware.
- `resources/views/dashboard.blade.php`: reusable filters and filter-preserving calendar links.
- `resources/views/partials/calendar-filters.blade.php`: barangay/resource/date controls.
- `resources/views/calendar-overview.blade.php`: removed after the single-resource UI correction.
- `resources/views/components/layouts/user.blade.php`: administrator Calendar navigation.
- `resources/views/facility-show.blade.php`: shared calendar link.
- `public/sw.js`: network-only calendar navigation and cache version update.
- `tests/Feature/CentralizedCalendarTest.php`: shared availability, filters, privacy, status refresh, equipment, legacy handling, and authorization coverage.
- `tests/Feature/ReservationCalendarTest.php`: expected schedule links now include the barangay filter.
- `tests/Feature/AdminAnalyticsTest.php`: expected admin navigation includes Calendar.
- `tests/Frontend/calendar-filters.test.cjs`: automatic barangay, resource, and date changes.
- `tests/Frontend/calendar-cache.test.cjs`: fresh calendar fetch and offline fallback checks.
- `CENTRALIZED_CALENDAR_REPORT.md`: implementation and verification report.

## Verification

Five new feature tests cover same- and cross-barangay bookings, six viewer role/barangay combinations, stale ownership snapshots, barangay/resource filters, equipment, unchanged home barangays, a single reservation record, private data exclusion, residents-only access, unrelated admin denial, owner/super-admin management, legacy records, and status changes on refresh.

The existing pending/rejected, overnight, payment, notification, and management tests are retained. Two calendar link expectations were updated to include the new filter parameter.

Verification before the single-resource UI correction:

- `php artisan test --compact`: 182 passed, 2,174 assertions.
- `node --test tests/Frontend/*.test.cjs`: 11 passed.
- `node tests/Frontend/admin-analytics-cache.cjs`: 32 private-page cache checks passed.
- `git diff --check`: passed.
- `/calendar` route registration verified.

## Single-resource UI correction

The filter row contains only Barangay, Resource, and Selected Date. It uses three desktop columns and the existing stacked mobile layout. Resource labels use HTML bullet entities to avoid broken separators. Old all-resource URLs redirect to an unselected calendar. Month navigation preserves the selected barangay/resource, and date changes update the month automatically. Calendar availability, accepted-only status rules, and management authorization are unchanged.

Final validation after the UI correction:

- `php artisan test --compact`: 183 passed, 2,246 assertions.
- `node --test tests/Frontend/*.test.cjs`: 14 passed.
- `git diff --check`: passed.

The later progressive-filter update supersedes the three-field and All Barangays behavior above. See CALENDAR_FILTER_REPORT.md for the current four-field flow and validation results.
