# Facility capacity and equipment availability

## Inspection and decisions

Facilities and equipment share the `facilities` table and `Facility` model, distinguished by `category`. Before this change, both used `capacity` and required reservation `attendees`. The management form explicitly called capacity “Number of people”; equipment had no stock or unit-count field. There was no safe basis for interpreting historical attendees as equipment units.

The user chose separate, administrator-verified stock and legacy whole-resource holds. No existing capacity, attendee, payment, notification, conflict, or reservation values are converted.

Facility requests already enforced attendee limits. Accepted rescheduling checked resident booking overlaps, but initial acceptance only checked Official Use. This allowed two overlapping pending facility requests to be accepted. The shared acceptance/rescheduling check now prevents that under the existing resource lock. Multiple pending facility requests remain possible.

Pricing is a resource-level hourly rate, including equipment. `Reservation::calculatedAmount()` multiplies that snapshot by duration in minutes, with integer-cent rounding; acceptance records the administrator-confirmed amount. Quantity does **not** multiply the rate. Paid totals, payment records, refund confirmation, and notification triggers retain their existing behavior.

The existing role policy already permits Super Admin resource and Official Use management. This change retains those permissions; it does not add operational privileges. Barangay Admin mutations remain scoped to their barangay. All Schedules retains the existing cross-barangay browsing and Official Use purpose privacy rules.

## Schema and historical records

Migration: `2026_10_18_000001_add_equipment_quantities.php`.

- `facilities.total_quantity`: nullable unsigned integer. NULL means stock has not been verified. Authorized admins enter a positive whole number through management.
- `reservations.quantity` and `official_uses.quantity`: nullable unsigned integers. NULL on equipment means a historical whole-resource hold, not an assumed number of units.
- Existing `capacity` and `attendees` become nullable so new equipment records do not require invented people counts. Existing values remain unchanged.

New equipment requests require verified stock and an explicit positive quantity. Historical accepted equipment bookings continue to occupy the entire resource, even after stock is verified; increasing stock cannot turn an old exclusive booking into shared inventory. Historical pending bookings can still be accepted as exclusive holds, or the resident can explicitly enter a verified quantity when editing the pending request. Historical Official Use can retain an exclusive hold or be explicitly updated with a verified quantity. Accepted reservation quantities remain fixed through rescheduling. Reviewing an accepted legacy booking does not silently rewrite its quantity or payment.

Admins may leave an existing unverified stock value blank when changing administrative availability or other metadata. This keeps the manual Available/Unavailable control usable without guessing stock. Verified stock cannot be cleared, and new equipment always requires positive stock.

The migration is not a data backfill. Rollback removes the new columns only while none contain recorded values; otherwise it fails before dropping anything. Relaxed nullability remains on rollback to avoid inventing values for newer equipment records. No reset, deletion, or `migrate:fresh` is used.

## Availability and locking

`ReservationAvailability` is the shared authority for equipment stock, facility acceptance, selected-time availability, and calendar slots. It reads accepted reservations plus active/conflicting Official Use records. Pending, rejected, and cancelled reservations allocate nothing. Accepted bookings in an unresolved Official Use conflict continue to allocate their units.

Intervals are half-open `[start, end)`. Start and end deltas are clipped to the requested interval and grouped by timestamp. The maximum running sum is the peak allocation; availability is `max(0, total_quantity - peak)`. A 40-unit 08:00–09:00 booking followed by a 50-unit 09:00–10:00 booking therefore leaves 50 of 100 units available for 08:00–10:00. Custom minutes and overnight periods reuse `ReservationPeriod`.

Acceptance, accepted rescheduling/cancellation, Official Use creation/editing, pending editing, and resource updates all take the resource row lock before altering relevant data. Competing acceptances on the same MySQL/InnoDB resource serialize. The second approval recomputes inventory after the first commits; insufficient inventory leaves it pending and creates no payment. Stock itself is never decremented, so cancellation and rescheduling cannot double-release inventory.

Stock reductions cannot fall below explicit upcoming allocations. A resource with scheduling history cannot switch between Facility and Equipment, because that would change historical booking meaning.

## Official Use

Equipment Official Use records explicit units; overlapping official schedules can coexist within stock. Resident bookings may occupy the remainder. Official Use may take priority over accepted resident allocations using the existing conflict workflow. Conflicts are recorded only for accepted bookings whose overlap contains an overallocated instant. Affected accepted bookings and paid amounts remain intact. Existing pending-request cancellation behavior applies only when the request can no longer fit.

When an Official Use changes, or an accepted equipment booking is rescheduled/cancelled, related equipment conflicts are reevaluated. This also resolves conflicts for other residents when sufficient stock becomes available. Existing decision history and notifications are retained; continuing conflicts do not trigger duplicate notices. Cancellation still moves paid receipts to Refund Pending, not Refunded.

## Interface

Facility management and details label capacity in people. Equipment management and details show Total Quantity; equipment request forms show Quantity Requested and Available Quantity for the selected interval. Pending editing supports correcting quantities. The availability endpoint exposes aggregate stock only and is private/no-store.

Calendar slots remain available when equipment stock remains. Read-only schedule cards show booked units or an explicit legacy whole-resource hold, without resident names or personal details. Manual Available/Unavailable remains separate from calculated inventory. Marking a resource unavailable blocks new requests but preserves accepted records and their visibility in All Schedules.

The layout, blue styling, rounded cards, calendar filters, event grouping, and schedule scrollbar are retained. New behavior uses plain JavaScript and Blade; no dependency or Vite build is required.

## Validation and rollout

`phpunit.xml` points tests at a separate nonexistent configuration-cache path, so a cached local MySQL configuration cannot override the isolated SQLite test settings. The existing safety guard remains enabled.

Relevant tests include `FacilityCapacityAcceptanceTest`, `EquipmentQuantityTest`, existing acceptance/pending-edit, Official Use, facility management, calendar, payment, email, and barangay-scoping suites. `tests/Integration/equipment-acceptance-mysql.php` uses randomly prefixed InnoDB scratch tables and real acceptance code in two workers. It verifies migration preservation, guarded rollback, resource-lock contention, exactly one winning 70-unit request out of two against 100 units, and no payment for the losing request. It never writes application records and cleans up only its own scratch tables.

Browser fixtures can be rendered by setting `EQUIPMENT_UI_RENDER_DIR=storage/app/equipment-ui-check` when running `EquipmentQuantityTest`, then running `node tests/Frontend/equipment-quantity-browser.cjs`. The browser checks 1440, 768, 390, and 320-pixel widths, distinct fields, category switching/reset, Official Use quantity controls, live availability, and read-only schedules.

For deployment, run the read-only `php tests/Integration/equipment-data-audit.php` before and after applying only the new migration. Its counts and SHA-256 fingerprints exclude new quantity columns and can confirm that historical records were untouched. After deployment, administrators must verify equipment stock in management before new quantity-based requests can be submitted. Do not bulk-copy capacity or attendees into quantities.

## Executed results — October 18, 2026

- Main PHP regression run: **197 passed**, 2,978 assertions.
- Additional calendar, Official Use, overnight, resident, and permission run: **141 passed**, 2,327 assertions.
- Final equipment/management/detail verification: **30 passed**, 293 assertions.
- Those passing runs cover **294 distinct PHP test cases**; the runs intentionally overlap.
- MySQL migration and concurrent acceptance: **11 checks passed** using isolated scratch tables and two independent workers.
- Chrome responsive/form checks: **20 scenarios passed** across five pages and four viewport widths.
- Existing JavaScript time-validation, calendar-cache/filter, payment-confirmation, and notification tests: **24 passed**.
- PHP formatting completed with the existing Pint formatter; `git diff --check` passed.

Initial runs exposed a Blade directive formatting error and obsolete fixtures that still submitted attendees for equipment or hid accepted bookings on manually unavailable resources. These were corrected and rerun. Test safety initially blocked the cached application configuration; the separate PHPUnit configuration-cache path fixed this without disabling the database guard.

The new migration was applied successfully to the local MySQL database using its explicit migration path. Before/after counts and SHA-256 fingerprints matched for every preexisting field in all six audited tables:

| Table | Preserved rows |
| --- | ---: |
| facilities | 6 |
| reservations | 7 |
| official_uses | 3 |
| payments | 6 |
| official_use_conflicts | 3 |
| notifications | 47 |

The two existing equipment resources remain **unverified**, ready for authorized admins to enter actual stock. The two existing equipment reservations (one cancelled, one rejected) retain NULL quantities and their original values. No active equipment booking required conversion. No unresolved pricing or historical-data decision remains. Accepted legacy holds are intentionally retained as exclusive bookings; this change does not add a new accepted-quantity amendment workflow.

## Files changed

Backend and routing:

- `app/Http/Controllers/FacilityController.php`
- `app/Http/Controllers/OfficialUseController.php`
- `app/Http/Controllers/PendingReservationController.php`
- `app/Http/Controllers/ReservationController.php`
- `app/Models/Facility.php`
- `app/Models/OfficialUse.php`
- `app/Models/Reservation.php`
- `app/Support/FacilityCatalog.php`
- `app/Support/OfficialUseScheduling.php`
- `app/Support/ReservationAvailability.php`
- `app/Support/ReservationCalendar.php`
- `routes/web.php`
- `database/migrations/2026_10_18_000001_add_equipment_quantities.php` (new)

Views and JavaScript:

- `resources/views/calendar/availability.blade.php`
- `resources/views/calendar/schedules.blade.php`
- `resources/views/calendar/quantity.blade.php` (new)
- `resources/views/components/layouts/user.blade.php`
- `resources/views/facilities/show.blade.php`
- `resources/views/facilities/partials/facility-card-content.blade.php`
- `resources/views/facilities/partials/facility-form-fields.blade.php`
- `resources/views/facilities/partials/facility-reservation-form.blade.php`
- `resources/views/facilities/partials/facility-view-modal.blade.php`
- `resources/views/official-uses/dialogs.blade.php`
- `resources/views/official-uses/index.blade.php`
- `resources/views/reservations/admin-dialogs.blade.php`
- `resources/views/reservations/resident-card.blade.php`
- `public/js/resource-quantity.js` (new)

Tests and documentation:

- `phpunit.xml`
- `tests/Feature/EquipmentQuantityTest.php` (new)
- `tests/Feature/FacilityCapacityAcceptanceTest.php` (new)
- `tests/Feature/AllResourcesCalendarTest.php`
- `tests/Feature/CentralizedCalendarTest.php`
- `tests/Feature/CrossBarangayReservationTest.php`
- `tests/Feature/FacilityViewTest.php`
- `tests/Feature/OfficialUseEditTest.php`
- `tests/Feature/OfficialUseTest.php`
- `tests/Feature/ResidentFacilityActionsTest.php`
- `tests/Frontend/equipment-quantity-browser.cjs` (new)
- `tests/Integration/equipment-acceptance-mysql.php` (new)
- `tests/Integration/equipment-data-audit.php` (new, read-only)
- `docs/equipment-capacity-availability.md` (this report)
