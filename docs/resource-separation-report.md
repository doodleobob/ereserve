Facilities and Equipment are now separate pages for residents, Barangay Admins, and Super Admins. The existing Facility model, facilities table, ReservationAvailability service, and reservation/payment workflows remain in use.

Implemented behavior:

- Residents have Dashboard, Facilities, Equipment, All Schedules, My Reservations, and Profile Settings navigation, plus separate browsing shortcuts on the dashboard.
- Both admin roles have a Resources disclosure with Facility Management and Equipment Management. It supports native keyboard activation, mouse and touch, Escape, focus leaving, outside clicks, navigation closing, and active-page indicators. It expands in the navigation flow without covering content.
- Listing queries constrain category from the route before loading resources. Query parameters cannot switch a page to the opposite resource type. Resident search, relevant category, availability, and barangay controls remain.
- Shared add/edit/view forms use resource-specific labels. Facility forms contain attendee capacity; equipment forms contain total quantity and preserve stock verification behavior. Existing management endpoints remain compatible, and equipment mutations redirect to Equipment.
- Reservation Management stays centralized and adds an All / Facilities / Equipment filter. Official Use, All Schedules, Payments, and Analytics stay centralized.
- Equipment is included in the PWA private-path list. The worker cache version is advanced so older caches are retired.

Routes:

| Method and path | Route name | Result |
| --- | --- | --- |
| GET /equipment | equipment (new) | Equipment browsing for residents; Equipment Management for admins |
| GET /facilities | facilities (updated) | Facilities only; Facility Management for admins |
| GET /reservations?category=all, Facility, or Equipment | reservations.index (updated behavior) | Centralized resource-type filter |
| POST /facilities | facilities.store (retained) | Shared resource creation; category-specific success message and redirect |
| PATCH /facilities/{slug} | facilities.update (retained) | Shared updates; category-specific success message and redirect |
| DELETE /facilities/{slug} | facilities.destroy (retained) | Existing authorized action; category-specific success message and redirect |
| GET /facilities/{slug} | facilities.show (retained) | Both resource types; correct active navigation and back link |
| GET /facilities/{slug}/availability | facilities.availability (unchanged) | Existing equipment availability service |
| POST /facilities/{slug}/reservations | reservations.store (unchanged) | Existing reservation workflow |

Dashboard:

- Barangay Admin now has five cards: Available Facilities, Available Equipment, Pending Requests, Accepted Reservations, and Rejected Requests.
- Availability still means the existing manual Available status; the equipment card counts resource records, not stock units or time-dependent remaining quantity. Reservation counts still include both categories and retain barangay scoping.
- The responsive grid uses five columns on wide screens, three on desktop, two on tablets, and one on narrow mobile screens.
- Today's and Recent Reservations retain their ordering, limits, status badges, and dates. Both show resource type and times. Equipment quantities appear only when recorded; NULL quantities display Legacy whole-resource hold. Facilities show recorded attendees.
- Super Admin summary responsibilities are unchanged.

Permissions and data:

- Existing authentication, email verification, active-account middleware, and admin-role checks remain.
- Barangay Admin listing and mutation access remains restricted to the assigned barangay, even with a forged barangay query parameter.
- Super Admin retains existing system-wide resource visibility and authorized cross-barangay management.
- Residents cannot create, edit, or delete resources. Existing cross-barangay browsing and reservation access rules remain.
- No database schema changes, application-data edits, database reset, package installation, or real email sending was performed. PHP tests use enforced in-memory SQLite with array/fake email delivery.

Verification:

- PHP: php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --log-junit storage/app/resource-separation-results.xml - 613 passed, 0 failed; 8,796 assertions (99.192 seconds). This includes reservation availability, equipment quantity and legacy holds, Official Use, payments/refunds, notifications, authentication, and role/tenant regression tests.
- JavaScript: node --test tests/Frontend/*.test.cjs - 48 passed, 0 failed.
- Equipment filters: node tests/Frontend/resident-resource-filters-browser.cjs - 16 browser scenarios passed, including search, category, availability, barangay, URL restoration, and empty states.
- Dashboards: node tests/Frontend/dashboard-overview.cjs storage/app/dashboard-separation-check - both roles passed at 1280, 1024, 768, 390, and 320 pixels (10 layouts).
- Resource pages: node tests/Frontend/resource-separation-browser.cjs - 24 scenarios passed across three roles, both pages, and 320/768/1280/1440 pixels, including keyboard, mouse, touch, active links, no content overlap, and add/edit/view modals.
- git diff --check passed.
- Initial failures caused by old combined-navigation expectations were corrected. A missing /equipment PWA private-path entry was identified and fixed. Sandbox restrictions required PHP and Chrome tests to run outside the sandbox; their databases and rendered fixtures remained isolated.

Remaining attention:

- No known implementation issues. Automated browser checks use rendered test fixtures; they do not submit changes against the live application database.
- MySQL integration scripts were not run against the configured database. Existing availability and financial logic were covered by the PHP regression suite without modifying those services.

Files modified or added:

- app/Http/Controllers/FacilityController.php
- app/Support/AdminReservationQuery.php
- app/Support/DashboardOverview.php
- app/Support/FacilityCatalog.php
- public/css/app.css
- public/css/dashboard-overview.css
- public/js/mobile-navigation.js
- public/js/resources-navigation.js
- public/sw.js
- resources/views/components/layouts/user.blade.php
- resources/views/dashboards/operations.blade.php
- resources/views/dashboards/reservations.blade.php
- resources/views/dashboards/resident.blade.php
- resources/views/facilities/index.blade.php
- resources/views/facilities/partials/facility-form-fields.blade.php
- resources/views/facilities/partials/facility-view-modal.blade.php
- resources/views/facilities/show.blade.php
- resources/views/partials/mobile-navigation.blade.php
- resources/views/partials/user-navigation.blade.php
- resources/views/reservations/index.blade.php
- routes/web.php
- tests/Feature/AdminAnalyticsTest.php
- tests/Feature/DashboardOverviewTest.php
- tests/Feature/FacilityViewTest.php
- tests/Feature/FrontendAssetContractsTest.php
- tests/Feature/MobileNavigationTest.php
- tests/Feature/ProfileSecurityTest.php
- tests/Feature/ResidentFacilityActionsTest.php
- tests/Feature/ResourceSeparationTest.php
- tests/Frontend/dashboard-overview.cjs
- tests/Frontend/page-handlers.test.cjs
- tests/Frontend/pwa-private-pages.test.cjs
- tests/Frontend/resident-resource-filters-browser.cjs
- tests/Frontend/resource-separation-browser.cjs
- docs/resource-separation-report.md (this report)
