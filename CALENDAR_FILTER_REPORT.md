# Progressive calendar filters

The calendar now uses Barangay, Resource Type, Resource, and Selected Date. All four fields submit automatically; there is no apply/view button.

- All Barangays is removed. Users and barangay admins default to their registered/assigned barangay. A super admin with no home barangay defaults to the first participating barangay from the database.
- Resource Type offers a placeholder, Facility, and Equipment. Before selecting a type, the Resource dropdown is disabled and contains no resource list.
- Resources are queried by their owning barangay and category. Changing barangay preserves type but clears resource. Changing type clears resource. Invalid or incompatible resource selections never load another calendar as a fallback.
- Placeholders distinguish Select Resource Type First, Select Facility, Select Equipment, and empty-category results.
- Selecting a resource immediately loads its calendar. Date changes update the month, and month/day/slot navigation preserves barangay, type, and resource.
- Existing direct resource links infer the owner and type. Obsolete all-barangay/all-resource links redirect without those obsolete values.
- Desktop uses four columns, with a wider Resource field. The existing mobile breakpoint stacks the fields.

Calendar calculations remain in ReservationAvailability. Accepted-only availability, cross-barangay bookings, requester privacy, and resource-owner management policies are unchanged. Viewing another barangay changes no account, reservation, or ownership record. No database migration is required.

## Files changed for this request

- `app/Http/Controllers/DashboardController.php`: type validation, specific-barangay defaults, dependent selection, legacy URL cleanup.
- `app/Support/FacilityCatalog.php`: resource query requires owner barangay and category.
- `resources/views/partials/calendar-filters.blade.php`: four automatic dependent fields and placeholders.
- `resources/views/dashboard.blade.php`: type-preserving navigation and category-specific empty messages.
- `public/css/app.css`: balanced four-column layout, retaining mobile stacking.
- `tests/Feature/CentralizedCalendarTest.php`: progressive-filter coverage and updated expectations.
- `tests/Feature/ReservationCalendarTest.php`: expected links now preserve resource type.
- `tests/Frontend/calendar-filters.test.cjs`: type-change reset and automatic submission check.
- `CALENDAR_FILTER_REPORT.md`: this report.

## Verification

Feature coverage checks defaults for all roles, super-admin fallback, ownership-plus-category filtering, incompatible selection resets, empty equipment, invalid types, unchanged home barangays, single-calendar display, month navigation, obsolete URLs, and shared accepted-only availability. Existing privacy and authorization tests verify unrelated admins cannot accept/reject/change payments while resource owners and super admins retain management access.

Frontend tests exercise automatic barangay, type, resource, and date changes. Existing payment, notification, account, and calendar cache tests are retained.

Final results:

- `php artisan test --compact`: 184 passed, 2,316 assertions.
- `node --test tests/Frontend/*.test.cjs`: 15 passed.
- Laravel Pint: passed for changed PHP implementation and feature tests.
- `git diff --check`: passed.

`CENTRALIZED_CALENDAR_REPORT.md` also now points to this report to identify the superseding UI behavior.
