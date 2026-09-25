# Payment and notification implementation

Implemented the requirements in `specs/payment_notifcation.md`.

## Existing architecture and features

The project uses Laravel 13, Eloquent, MySQL, Blade, and shared plain CSS/JavaScript. `FacilityCatalog` applies barangay scoping; reservation controllers allow admins to manage their barangay and super admins to manage across barangays. Both roles use the shared user layout and blue header. The application uses inline SVG icons.

There were no pricing/payment columns or database notification table. `User` already uses `Notifiable`; the existing security email notification remains unchanged. Existing reservation status values are lowercase `pending`, `accepted`, and `rejected`.

## Database and pricing

Applied the additive migration `2026_09_25_000001_add_reservation_pricing_and_notifications.php` to the configured development database. No database reset, refresh, or wipe commands were used.

- `facilities.hourly_rate`: DECIMAL(10,2), default 0.00. Existing facilities start at zero; admins can set their rates.
- `reservations.hourly_rate_snapshot`: nullable DECIMAL(10,2). New submissions capture the facility rate server-side under a row lock. Client-supplied price fields are ignored during submission.
- `reservations.total_payment`: nullable DECIMAL(12,2), confirmed by an authorized admin.
- Standard Laravel `notifications` table with UUID, notifiable relation, JSON-encoded data, read timestamp, and timestamps.

Historical reservation rates and totals remain null because no original pricing was recorded. Views label the historical rate/calculation as “Not recorded”; legacy accepted totals show “Awaiting admin confirmation.” Admins can confirm totals without inventing historical rates.

Facility create/edit forms include Hourly Rate. Server validation rejects negative values, invalid numbers, scientific notation, more than two decimal places, and values beyond the column limits. Zero-priced facilities are supported. For compatibility with existing callers, omitted facility rates use the database default on creation and remain unchanged on update. The rendered forms require a rate.

Facility cards, details, and the dashboard reservation form show Philippine Peso hourly rates. Existing reservations retain their snapshots after rate changes. Duration uses the stored same-day start/end times. Calculated Amount multiplies snapshot cents by duration minutes, divides by 60, and rounds half-up to cents using integer arithmetic.

Reservation Management displays duration, snapshot rate, suggested calculation, and editable Total Payment. Save works for pending and accepted reservations. Accept submits and validates the admin-confirmed total and atomically stores it with the status transition. Payment corrections preserve the accepted status. Users cannot update either pricing field through the controllers.

Pending/rejected user reservation views contain no calculated amount or total payment. Accepted reservations display the current final total read-only, “Pay at Barangay,” and instructions to visit assigned staff and bring a valid ID. Acceptance does not record payment completion; no online payment gateway was added.

## Notifications and interface

All reservation notifications use Laravel's database channel synchronously within the reservation/payment transaction:

- New Reservation Request goes only to admins whose barangay matches the reservation. Super admins are not automatically subscribed.
- Reservation Accepted goes only to the owner on the actual pending-to-accepted transition. Row locks prevent duplicate concurrent acceptance notifications.
- Total Payment Updated goes only to the owner when an accepted reservation's amount actually changes. It includes previous and updated amounts. Equivalent values such as `1300` and `1300.00` do not generate duplicates.

Messages use actual facility, date, time, rate, and total values, with payment and identification instructions for owners. Notification access starts from the authenticated user's relation, applies their current barangay, and prevents a demoted admin from viewing former administrative request notifications. Reservation destination pages independently apply existing role, ownership, and barangay filters.

The matching SVG bell sits immediately before Logout in the blue header. The dropdown supports unread/read styling, counts capped visually at 99+, mark-all-read, and paginated history. “View Reservation” uses a CSRF-protected POST to mark the notification read and redirects to the corresponding reservation within the existing management/list page.

Same-origin polling runs every 15 seconds while the page is visible, avoiding replacement of an expanded history page. Admin request toasts are dismissible and expire after 12 seconds. Session storage prevents repeated toasts after refresh; persistent notifications remain in the database. The dropdown and toast area have viewport limits and scrolling. Escape and outside clicks close the dropdown. Notification text is inserted with `textContent`.

The existing service worker cached all GET responses. A narrow `/notifications` network bypass prevents stale counts and cached notification data from another login. Other PWA behavior is unchanged.

## Routes and controller changes

- Added `PATCH /reservations/{reservation}/payment` (`reservations.payment`).
- Existing acceptance route now requires a valid submitted Total Payment for a pending reservation.
- Added `GET /notifications` (`notifications.index`), `POST /notifications/read-all` (`notifications.read-all`), and `POST /notifications/{notification}/open` (`notifications.open`).
- Reservation list accepts a scoped `reservation` query parameter for notification destinations.
- All new routes remain under existing authenticated/verified middleware.

## Verification

- Full PHP suite: **89 tests passed, 885 assertions**, including calendar, overlapping requests, availability/in-use behavior, barangay/role authorization, facility management, email verification, profile security, and email 2FA.
- After adding role-change notification filtering, the focused payment/notification suite passed again: **6 tests, 128 assertions**.
- Frontend behavior suite: **4 tests passed** using Node's built-in test runner and a DOM stub. Covers badge cap, safe text rendering, CSRF destination forms, dropdown/Escape behavior, toast dismissal/expiry/deduplication, mark-all-read, and service worker cache bypass.
- `node --check` passed for notification JavaScript and the service worker; `git diff --check` passed. Laravel Pint formatted changed PHP files.
- Database tests enforce SQLite `:memory:` in the existing test harness and do not reset the development MySQL database.
- The incremental development migration completed successfully.
- No actual browser was available for visual inspection. Responsive CSS and rendered Blade output were checked, but desktop/tablet/mobile appearance still needs a browser review. Concurrency locking was reviewed in code, not stress-tested against MySQL.

## Complete implementation file list

Modified:

1. `app/Http/Controllers/FacilityController.php`
2. `app/Http/Controllers/ReservationController.php`
3. `app/Http/Controllers/ReservationPageController.php`
4. `app/Models/Facility.php`
5. `app/Models/Reservation.php`
6. `app/Support/FacilityCatalog.php`
7. `public/css/app.css`
8. `public/sw.js`
9. `resources/views/components/layouts/user.blade.php`
10. `resources/views/dashboard.blade.php`
11. `resources/views/facilities.blade.php`
12. `resources/views/facility-show.blade.php`
13. `resources/views/partials/facility-form-fields.blade.php`
14. `resources/views/reservations/index.blade.php`
15. `routes/web.php`
16. `tests/Feature/ReservationCalendarTest.php` (existing acceptance tests now provide an explicit zero total)

Created:

17. `app/Http/Controllers/NotificationController.php`
18. `app/Notifications/ReservationActivity.php`
19. `app/Support/Money.php`
20. `database/migrations/2026_09_25_000001_add_reservation_pricing_and_notifications.php`
21. `public/js/notifications.js`
22. `resources/views/partials/notifications.blade.php`
23. `tests/Feature/PaymentNotificationTest.php`
24. `tests/Frontend/notifications.test.cjs`
25. `specs/payment_notification_report.md`

The original untracked specification file was read and left unchanged.
