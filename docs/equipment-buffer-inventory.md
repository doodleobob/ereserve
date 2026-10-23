# Equipment buffer and inventory implementation

Implemented on `main`. No commit or push was made. No application-data migration, reset, wipe, or stock backfill was executed.

## Inspection and integration

The existing implementation already provided the following:

| Concern | Existing source reused |
| --- | --- |
| Total equipment stock | `facilities.total_quantity`; NULL means unverified, not zero |
| Scheduled requests | `reservations`, including original date, start/end and quantity |
| Accepted allocation | `ReservationAvailability` peak-concurrency sweep, with accepted reservations and active/conflicting Official Use |
| Physical release | `resource_usages.released_quantity`, with immutable `resource_usage_events` |
| Physical return | `resource_usages.returned_quantity`, including partial returns and history |
| Physical availability | `ResourceUsageManagement::inventory()`, derived from stock and outstanding loans |
| Conflict checks | Shared resource-row locks, transactions, availability validation and `OfficialUseScheduling` |

Pending requests do not consume scheduled quantities; acceptance revalidates them. Rejected/cancelled requests do not consume scheduled allocation. Unreturned loans remain physical obligations after cancellation or schedule expiry.

## Implemented behavior

- Equipment reservations and equipment Official Use reserve their quantity through **scheduled end + 60 minutes**, with exclusive end boundaries. Facility reservations retain their existing timing rules.
- The existing interval sweep calculates maximum simultaneous allocation, including the requested booking's own buffer. Lookback includes two dates to cover overnight bookings whose buffer reaches another date.
- Acceptance, creation, edits, rescheduling, Official Use conflict handling, live hourly choices, calendar slot availability, and stock-reduction validation share these rules.
- Original booking times, billing duration and payment calculations are unchanged. Availability summaries and live booking feedback display the buffer and effective allocation end separately.
- Physical stock remains separate from scheduled availability. Early returns do not release scheduled allocation. Buffer expiry does not return loans. Release checks usable physical stock and current Official Use commitments.
- **Equipment Management → Inventory** contains searchable, paginated inventory, detail modals, confirmed adjustment forms, paginated history, and links to existing borrowed/overdue Resource Usage lists.
- Inventory shows recorded stock, usable stock, physically available stock, borrowed units, recorded damaged/unavailable units, overdue units, barangay, status, and last recorded adjustment time.
- Adjustments support additions, reductions of usable stock, damage, repairs, and disposal of damaged stock. Damage/repair leave recorded stock unchanged. Disposal decreases recorded and damaged quantities together.
- Adjustments require a positive quantity, type, reason, confirmation and retry key. The server supplies responsible administrator, barangay and timestamp. Repeating the same retry key applies the adjustment once.
- Stock cannot become negative, damaged units cannot exceed recorded stock, and usable stock cannot fall below outstanding loans or existing explicit future allocations/buffers.
- Existing Equipment form stock edits also require a reason and use the same validation/audit path. Unverified stock can be explicitly verified there, preserving NULL in the history's before value. Zero recorded stock is supported.
- Equipment with inventory history cannot be deleted or reclassified; it can be marked unavailable. Adjustment records are protected against UPDATE/DELETE by database triggers.

Barangay admins can manage only their barangay's stock. Residents cannot access the Inventory tab or adjustment endpoint. **The existing project explicitly permits Super Admin stock edits across barangays** (`FacilityController` and `ResourceSeparationTest`); this permission is preserved under the exception in the request. Resource Usage release/return permissions remain unchanged.

## Database changes and safe application

One migration was added:

`database/migrations/2026_10_23_000001_add_equipment_inventory_adjustments.php`

It adds `facilities.damaged_quantity` with default zero and the `equipment_inventory_adjustments` audit table, including foreign keys, indexes, retry-key uniqueness and immutable-history triggers. It does not update existing `total_quantity`, reservations, releases, returns or usage events. No second inventory balance or release/return system was created.

Zero damaged units initially means **no damage has been recorded in this new tracking field**. It does not reconstruct historical damage. Existing recorded stock and NULL/unverified values are retained exactly. If historical `total_quantity` excluded unrecorded damaged stock, reconcile the actual count explicitly with a reason; do not infer or backfill it.

Review the migration, take the normal deployment backup, and inspect pending migrations. With the existing project migrations already applied:

```powershell
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_10_23_000001_add_equipment_inventory_adjustments.php
php artisan migrate --path=database/migrations/2026_10_23_000001_add_equipment_inventory_adjustments.php
```

The production deployment account needs the existing application's schema-change permissions, including CREATE TRIGGER. Use `--force` only as part of the normal production deployment procedure. If prerequisite migrations are pending, review those before applying them. No asset build is required for these public CSS/JS and Blade changes; use the project's normal cache refresh procedure if views/routes are cached.

Rollback refuses to remove recorded adjustment history or recorded damaged units. Existing accepted schedules are not rewritten or cancelled by the migration. Review already accepted back-to-back equipment bookings because they now have buffers and may exceed stock under the new rules.

## Verification

Phase 1 was tested before Phase 2 began: 42 equipment/buffer/summary/calendar tests and 61 hourly-booking/usage/Official Use regression tests passed. The initial regression failures were expectations for the former no-buffer behavior; those assertions now reflect the requested timing rule.

The initial Phase 2 run found that the new endpoint needed inclusion in the project's JSON-validation route list. That was fixed. The existing summary browser test exposed long-name overflow; scoped CSS wrapping fixed it.

- Final complete PHPUnit run: **718 tests passed, 10,244 assertions**, including disposal and independent pagination. No failures or errors remained. Results: `storage/logs/equipment-full-suite-final.xml`.
- Focused final buffer, inventory, and resource-permission run: **19 tests passed, 192 assertions**, including disposal.
- Isolated MySQL harness: **17 checks passed**, covering concurrent buffer-only acceptance, release and return races, duplicate return/adjustment retries, concurrent stock reduction/damage, immutable history and payment isolation.
- JavaScript regression tests: **17 passed**.
- Inventory browser checks: **14 role/viewport combinations passed**, 375–1920 px, covering scrolling, tabs, modals, focus, confirmation, validation and duplicate submission.
- Equipment Availability Summary browser checks: **14 combinations passed**, including risk indicators, server conflicts and facility compatibility.
- Resource Usage browser checks: **14 combinations passed**, including menus, dialogs and retry keys.
- Laravel Pint and `git diff --check` were run.

The complete Git diff was reviewed. Scheduled quantities still use one shared allocation sweep; usable quantity is derived centrally on `Facility`, and physical inventory remains in `ResourceUsageManagement`. Facility timing, Official Use workflows, payment/refund behavior, notifications and existing PWA tests passed within the full suite. New management routes use the existing authorization and JSON-validation mechanisms.

PHPUnit enforces in-memory SQLite. The MySQL harness creates isolated databases/accounts and verifies that its test account cannot read application data. The two test databases were retained for review by the existing harness:

- `ereserve_usage_test_20261022163115_3962809d` — Phase 1 (13 checks).
- `ereserve_usage_test_20261022164336_e3be8000` — Phase 2 (17 checks).

Test artifacts are under `storage/logs/equipment-*.xml`, `storage/app/inventory-check`, `storage/app/equipment-summary-check`, `storage/app/ui-consistency-check` and the existing `storage/app/usage_mysql_*` directories. Test database connection artifacts contain generated credentials; keep them private. Application records were not migrated or changed by these checks.

Reproduction commands:

```powershell
php -d xdebug.mode=off -d opcache.enable_cli=0 vendor/bin/phpunit --do-not-cache-result
php -d xdebug.mode=off -d opcache.enable_cli=0 tests/Integration/resource-usage-mysql.php
$env:INVENTORY_UI_RENDER_DIR='storage/app/inventory-check'
php -d xdebug.mode=off -d opcache.enable_cli=0 vendor/bin/phpunit tests/Feature/EquipmentInventoryTest.php
node tests/Frontend/equipment-inventory-browser.cjs
php -d xdebug.mode=off -d opcache.enable_cli=0 tests/Frontend/equipment-summary-fixtures.php
node tests/Frontend/equipment-summary-browser.cjs
node tests/Frontend/resource-usage-browser.cjs
node --test tests/Frontend/reservation-time-validation.test.cjs tests/Frontend/reservation-datatable.test.cjs
```

## Manual testing

Use staging or test records after applying the migration:

1. Create equipment with 160 units. Accept 100 units for 7–8 PM on a future date. Confirm the summary displays an 8–9 PM buffer.
2. Try accepting 140 units for 8–9 PM: it must fail with 60 available. Change the request to 60: it can be accepted. A request starting at 9 PM is clear of the first reservation's buffer, subject to the second reservation and other allocations.
3. Test an overnight booking, reschedule it, and cancel it. Check calendar quantities and Official Use conflicts. Confirm facility bookings still allow their previous timing behavior and billing uses only the original reservation duration.
4. Release equipment in parts through Resource Usage, then return only part. Confirm Inventory's borrowed, physical and overdue quantities match. An early return must not remove scheduled allocation, and an expired buffer must not allow physically unreturned units to be released again.
5. Open Equipment → Inventory. Search, paginate, open details and follow borrowed/overdue links. Add stock with a reason; mark units damaged, restore repairs, and dispose of damaged units. Confirm the correct before/after balances and actor in history.
6. Attempt a negative/oversized change, an over-repair, an adjustment that consumes outstanding loans, or one that reduces capacity below a future accepted allocation. Each must fail without changing stock/history.
7. Change Total Quantity in the existing Equipment form. A changed count requires a reason; unchanged counts do not create audit entries. Verify previously unverified stock explicitly.
8. Check access with another barangay admin, a resident, and Super Admin. Cross-barangay admin writes and resident inventory access must fail server-side. Super Admin retains existing cross-barangay stock permission; release/return operations keep their existing restrictions.
9. Repeat the tab, modal, filter and error flows on mobile and installed PWA. These routes remain private under the existing service worker rules; inventory actions require connectivity.

## Limitations

- Historical damage and adjustments cannot be reconstructed; their absence is shown explicitly.
- The new buffer may expose conflicts between previously accepted bookings. Those bookings and payments remain preserved for administrators to resolve.
- Reducing usable capacity below existing allocations is blocked; resolve affected schedules before recording such reductions/damage. Unreturned equipment cannot be marked as physically present stock.
- Physical availability is a current snapshot, not a promise that overdue equipment will be returned for a future booking.
- Automated responsive browser tests passed; installation and offline behavior on a physical PWA device were not manually exercised. Existing PWA tests are included in the complete suite.
- Application migrations remain unapplied; the Inventory tab requires the new migration before use.

## Files created

- `app/Http/Controllers/EquipmentInventoryController.php`
- `app/Models/EquipmentInventoryAdjustment.php`
- `app/Policies/EquipmentInventoryAdjustmentPolicy.php`
- `database/migrations/2026_10_23_000001_add_equipment_inventory_adjustments.php`
- `public/css/equipment-inventory.css`
- `resources/views/shared/facilities/partials/inventory-tab.blade.php`
- `tests/Feature/EquipmentBufferTest.php`
- `tests/Feature/EquipmentInventoryTest.php`
- `tests/Frontend/equipment-inventory-browser.cjs`
- `docs/equipment-buffer-inventory.md`

## Files modified

- `app/Http/Controllers/FacilityController.php`
- `app/Models/Facility.php`
- `app/Services/ResourceUsageManagement.php`
- `app/Support/EquipmentAvailabilitySummary.php`
- `app/Support/FacilityCatalog.php`
- `app/Support/OfficialUseScheduling.php`
- `app/Support/ReservationAvailability.php`
- `app/Support/ReservationPeriod.php`
- `app/Support/ResourceUsageQuery.php`
- `bootstrap/app.php`
- `public/css/management-tables.css`
- `public/js/reservation-time-validation.js`
- `resources/views/shared/calendar/partials/equipment-schedule.blade.php`
- `resources/views/shared/calendar/partials/quantity.blade.php`
- `resources/views/shared/facilities/index.blade.php`
- `resources/views/shared/facilities/partials/facility-form-fields.blade.php`
- `resources/views/shared/reservations/partials/equipment-availability.blade.php`
- `resources/views/shared/resource-usage/index.blade.php`
- `resources/views/shared/resource-usage/inventory.blade.php`
- `routes/web.php`
- `tests/Feature/EquipmentAvailabilitySummaryTest.php`
- `tests/Feature/EquipmentCalendarPresentationTest.php`
- `tests/Feature/EquipmentQuantityTest.php`
- `tests/Feature/HourlyBookingTest.php`
- `tests/Feature/ResourceSeparationTest.php`
- `tests/Feature/ResourceUsageTest.php`
- `tests/Integration/resource-usage-mysql.php`
