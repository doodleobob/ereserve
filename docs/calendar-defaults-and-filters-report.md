# Reservation Calendar defaults and filter dependencies

## Result

The resident dashboard renders the current month's calendar immediately, with the user's registered barangay, All Types, All Facilities & Equipment, and today selected. Explicit valid URL selections take precedence. Existing resource-only links still infer their resource's barangay and type.

All Resources shows event counts on calendar dates and resource names beside the selected day's bookings and Official Use events. It does not calculate combined availability, combine overlapping bookings into conflicts, or offer booking/time-slot actions. A prompt asks the user to select a resource first. Empty results retain the calendar and a useful empty-day message.

Selecting a resource restores its existing availability calendar and booking controls. Changing barangay or type submits the existing filter form and refreshes both options and events. A resource is reset only when it no longer belongs to the selected barangay/type. All Types retains a valid selected resource. Date changes retain filters and navigate to the selected date's month.

## Implementation

The controller reuses `ReservationAvailability::calendarEvents()` separately for each matching resource. Its existing accepted-only selection, overnight splitting, legacy resource matching, and Official Use visibility remain authoritative. Combined events gain only public resource identifiers/names. Official Use details remain limited to the owning admin or Super Admin. The existing unavailable-resource rule continues to suppress reservation events while retaining permitted Official Use events.

The shared catalog's category argument is optional for All Types. Its barangay restriction remains in place. Calendar day rendering, the existing filter JavaScript, and existing blue-and-white CSS are reused. Cross-barangay reading remains available; management permissions are unchanged. Admin and Super Admin operational dashboards retain their existing role-based branch.

No changes were made to reservation mutations, payments, conflict decisions, transactions, locks, authentication, authorization policies, routes, PWA implementation, CSS, or dependencies.

## Files changed in this task

| File | Change |
| --- | --- |
| `app/Http/Controllers/DashboardController.php` | Default filters, combined read-only events, always-present calendar dates, specific-resource booking guard. |
| `app/Support/FacilityCatalog.php` | Optional category for the existing barangay-scoped calendar catalog. |
| `resources/views/calendar/filters.blade.php` | All options and resource metadata for dependency checks. |
| `resources/views/calendar/index.blade.php` | Always-visible calendar, event counts/resource labels, empty state, resource-only booking controls. |
| `public/js/page-filters.js` | Reset only invalid dependent resource selections. |
| `tests/Feature/AllResourcesCalendarTest.php` (new) | Six feature tests for defaults, filters, empty results, booking controls, privacy, statuses, overnight/legacy behavior, and Official Use visibility by role. |
| `tests/Feature/CentralizedCalendarTest.php` | Replace obsolete hidden-calendar/default-selection expectations with the requested defaults; retain other assertions. |
| `tests/Feature/ReservationCalendarTest.php` | Assert visible calendar and all-resource defaults on initial dashboard load. |
| `tests/Frontend/calendar-filters.test.cjs` | Exercise invalid resource resets and valid selection preservation. |
| `tests/Frontend/calendar-overview-browser.cjs` (new) | Actual rendered calendar/filter checks for five states at four viewport widths. |
| `docs/calendar-defaults-and-filters-report.md` (new) | This report. |

Pre-existing uncommitted changes were preserved. The starting Git status is recorded in `storage/app/calendar-default-check/git-status-before.txt`. No commit or push was performed.

## Verification

All Laravel tests and fixture generators used isolated in-memory SQLite (`APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, empty `DB_URL`). Configuration cache was bypassed with a task-specific nonexistent relative cache path. The existing regenerated route cache was used. Mail, session, and cache drivers were set to array, and queues to sync. Development/production data was not used.

| Check | Actual result |
| --- | --- |
| Before edits: Laravel filter `Calendar\|AcrossMidnight\|OfficialUse` | 73 passed, 1,195 assertions. |
| After edits: same relevant Laravel selection, including new tests | 79 passed, 1,409 assertions. |
| Full Laravel suite | 380 passed, 5,371 assertions. |
| `node --test tests/Frontend/*.test.cjs` | 38 passed, 0 failed; includes private-page PWA security tests. |
| `node tests/Frontend/admin-analytics-cache.cjs` | 32 private-page cache checks passed. |
| `node tests/Frontend/password-reset-pwa.cjs` | Passed fresh requests, offline fallback, no cached tokens, and untouched POST behavior. |
| All 13 browser suites | 359 checks passed, all on their first suite-run attempt. |
| PHP syntax checks | All five changed/new PHP files passed. |
| Laravel Pint `--test` | All five changed/new PHP files passed. |
| Node syntax checks | Changed filter script and both calendar JavaScript test files passed. |
| `git diff --check` | Passed. |

Browser results:

| Suite | Checks passed |
| --- | ---: |
| Calendar overview and filters (new) | 20 |
| UI consistency | 210 |
| Resident reservations | 4 |
| Facility layouts | 20 |
| Facility views/gallery | 18 |
| Resident facility actions | 12 |
| Time input layouts | 9 |
| Shared modals | 16 |
| Payments | 4 |
| Official Use | 4 |
| Reservation DataTable | 28 |
| Analytics | 4 |
| Admin/Super Admin dashboards | 10 |

The new browser suite uses Laravel-rendered fixtures and the actual filter script/CSS at 1440, 768, 390, and 320 pixels. It checks filter submissions, selected dates, resource names, empty states, resource-specific booking restoration, privacy, and horizontal overflow. Form submissions are captured in the browser harness; Laravel feature tests independently exercise the corresponding server requests.

JUnit results, generated HTML, browser logs, and suite status are under ignored `storage/app/calendar-default-check/`. Full Laravel verification used `vendor/phpunit/phpunit/phpunit --do-not-cache-result`; baseline/relevant runs added `--filter 'Calendar|AcrossMidnight|OfficialUse'`.

## Remaining consideration

Combined mode reuses existing queries per resource to preserve established visibility and scheduling behavior. Query cost therefore grows with the selected barangay's resource count. Production-scale performance was not benchmarked in this task; future optimization should retain the new regression coverage. No availability/conflict or concurrency behavior was changed.
