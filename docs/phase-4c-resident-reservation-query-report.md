# Phase 4C: Resident reservation listing query extraction (B1b)

Scope completed: focused resident-listing characterization and the B1b read-query extraction from the approved Phase 4A plan. No later architecture batch was implemented.

## 1. Baseline and preservation of existing work

Before edits, Git already contained the uncommitted Phase 4B OfficialUseController change, the OfficialUseQuery class, the Phase 4A/4B reports, OfficialUseListingTest, MoneyTest, and ReservationPeriodTest. All were preserved. SHA-256 comparisons also verified that those files and `app/Support/AdminReservationQuery.php` remained unchanged.

The original ReservationPageController was saved under the ignored `storage/app/phase4c-check/` verification directory before test or application edits. Its resident query and the existing `Reservation::forFacility`, `inBarangayFor`, and `managingBarangay` behavior were inspected, along with the reservation templates, routes, middleware, and admin query.

Laravel runs used `APP_ENV=testing`, SQLite `:memory:`, an empty database URL, array cache/session/mail drivers, and synchronous queues. Separate nonexistent configuration/route cache paths avoided cached application settings. The existing Tests\TestCase guard independently rejects any non-testing environment or non-memory SQLite connection. Neither that guard nor phpunit.xml was changed.

Existing browser fixture generators were inspected and ran with the same isolated environment. Their migrations and records existed only in temporary in-memory databases; facility image fixtures used the existing dedicated fixture upload directory. No development or production data was used or modified.

| Pre-edit baseline | Result |
| --- | --- |
| Relevant existing Laravel tests | **60 passed; 677 assertions** |
| Existing JavaScript suite | **36 passed** |
| PWA private-page regression tests | All 10 passed within the JavaScript suite |
| Private analytics cache checks | All 32 passed |
| Password reset PWA checks | Passed |

The relevant Laravel baseline included ReservationCalendarTest, ResidentFacilityActionsTest, CrossBarangayReservationTest, PaymentNotificationTest, ReservationDatatableTest, SaasBarangayTest, and AccountManagementTest. There were no existing failures in that baseline. Complete Laravel/browser suites were run after extraction; a complete pre-edit suite baseline is not claimed.

## 2. Characterization tests added

`tests/Feature/ResidentReservationListingTest.php` adds **17 tests and 370 assertions**. These passed against the original controller before extraction and against the extracted implementation afterward, with identical totals.

The tests document:

- Resident ownership filtering across Taft and Washington, including other residents in the same home barangay whose records must remain invisible.
- Facility snapshots, a current facility with a stale stored barangay, null facility IDs with matching historical slugs, missing resources, and a deleted facility whose relationship becomes null. Listing does not repair historical relationships.
- Existing `reservation` ID selection, its integer casts for malformed/array values, composition with other filters, and empty results for another user's reservation rather than a new 403 response.
- Search by stored facility name and purpose, whitespace, wildcard behavior, numeric text, long strings, and exclusion of new ID/current-facility-name/slug/requester-name search semantics.
- The four recognized stored statuses, unknown/array/empty status values, and legacy `approved` rows without normalization during listing.
- All resident sort modes, date/start-time defaults, ignored direction inputs, invalid or array sorts, and existing database tie behavior without adding an ID tie-breaker.
- Empty HTTP and explicit null defaults, retained selected view values, and ignored resident date/conflict/tenant/user/page filters.
- Collection semantics with 31 owned rows even when page or page-size parameters are present or invalid.
- Existing Booked/Pending/Rejected/Cancelled/Approved labels, managing barangay, hourly rate, null/zero amounts, accepted-only totals/duration, overnight labels, cancellation reason, and Official Use conflict decisions shown in the card.
- Reservation payment snapshots remaining the displayed values even when a separate payment record has a different amount/status.
- A listing leaving reservations, payments, official uses, conflicts, notifications, users, and facilities unchanged, with no dispatched notifications.
- Administrator current-facility ownership and nullable-resource fallback, Super Admin global access, paginated admin results, accepted admin direction behavior, and last-page recovery.
- Resident filter permissiveness versus existing admin validation; malformed search preprocessing before admin validation; and guest/verification/deactivation middleware response precedence.
- The existing non-admin role fallback to an own-reservation collection, without adding a new role or permission rule.

The first draft test run passed 16 of 17 tests. A new presentation assertion incorrectly checked that the entire page lacked “Booked,” although the existing resident status dropdown contains that option. The assertion was corrected to target the historical card's status markup before extraction. No application code or existing test assertion was changed to address this draft-test issue.

An optional test-only `RESIDENT_LISTING_CAPTURE_DIR` records deterministic fixture results during comparison runs. It captures rows, collection/paginator type, view selections, pagination metadata, SQL and bindings, and rendered HTML. Fixtures use a fixed clock, explicit account names/emails, and a fixed test session token so dynamic test identity does not obscure comparison. This option does not affect normal suite runs or application code.

## 3. Files created or modified

| File | Change |
| --- | --- |
| `app/Http/Controllers/ReservationPageController.php` | Replaced the resident query block with delegation and replaced the now-unused Reservation import with the query-class import. |
| `app/Queries/Reservations/ResidentReservationQuery.php` | New existing-behavior resident listing builder. |
| `tests/Feature/ResidentReservationListingTest.php` | New characterization and optional comparison capture tests. |
| `docs/phase-4c-resident-reservation-query-report.md` | This report. |

Only ReservationPageController is an existing application file modified in this phase. No existing test, model, service, route, middleware, policy, Blade, CSS, JavaScript, PWA, migration, dependency, or build-system file was changed.

Source snapshots, comparison captures, JUnit results, browser logs, and temporary verification runners are ignored artifacts under `storage/app/phase4c-check/`. Existing browser generators also refreshed their existing ignored fixture directories under `storage/app/`.

## 4. Extracted query responsibilities

`ResidentReservationQuery::forRequest(Request $request): Builder` returns the existing Eloquent builder. It retains the current request interpretation and then:

1. Eager-loads `facility` and `officialUseConflicts.officialUse`.
2. Restricts results to `reservations.user_id = authenticated user ID`.
3. Applies the existing optional integer `reservation` selector.
4. Groups the existing stored facility-name/purpose LIKE search.
5. Filters only the existing recognized statuses.
6. Applies the exact existing resident ordering.

The class does not add authorization, validation, pagination, date filters, ownership scopes based on facility/barangay, relation repair, presentation, writes, transactions, or locks.

The controller retains role branching, all current request preprocessing and selected view data, the unchanged administrator validation/query/pagination branch, resident `$query->get()`, the `reservations.index` view, and its existing data keys. AdminReservationQuery remains in its original location and is unchanged.

## 5. Before/after behavior comparison

A source comparison verified that the resident query body was transferred exactly. It also verified that replacing only that block and the import reproduces the final controller, leaving all retained code unchanged.

The comparison runs captured **99 listing cases: 92 resident/non-admin cases and seven administrator/Super Admin cases**. Every paired capture matched for row data, SQL and bindings, collection/paginator type, selected view values, pagination metadata, and rendered HTML. Direct-controller null/empty-array cases and exception/middleware cases are additionally covered by the same passing assertions before and after extraction.

| Contract | Identical before/after result |
| --- | --- |
| Resident ownership | Own reservations across managing barangays; no new tenant restriction. |
| Legacy/null resources | Historical records remain visible; no repair or exclusion. |
| Managing barangay | Current facility ownership when present; stored barangay when relationship is null. |
| ID selection | Existing `reservation` parameter and integer-cast interpretation. |
| Free-text ID search | No new ID search; numeric text still searches only facility snapshot/purpose text. |
| Facility text search | Stored `facility_name`, not the current related facility name. |
| Search `0` | Actual LIKE search, unlike the existing admin falsey-search behavior. |
| Empty search and wildcards | Existing trim/LIKE behavior. |
| Recognized status filters | Pending, accepted, rejected, cancelled. |
| Unknown/empty/array status | No status restriction; no new validation error. |
| Default/date/unknown sort | Reservation date descending, then start time descending. |
| Facility sort | Stored facility name ascending, then reservation date descending. |
| Status sort | Stored status ascending, then reservation date descending. |
| Resident direction | Ignored, including `asc`, `desc`, unknown, empty, and array values. |
| Sort ties | No additional ID or other tie-breaker. |
| Resident date/conflict/page filters | Remain unused; do not acquire admin filtering or validation. |
| Resident return type | Full Eloquent collection; no pagination. |
| View contract | Same `reservations.index` and all existing keys/markup. |
| Payment/status display | Existing reservation snapshots and Blade conditions. |
| Admin/Super Admin | Existing scoped/global paginated listings and validation behavior. |

The view keys remain `reservations`, `selectedStatus`, `selectedSort`, `selectedSearch`, `selectedDateRange`, `selectedFromDate`, `selectedToDate`, and `isAdmin`.

## 6. Authorization and response precedence

Residents continue to access the route through the existing authentication, verification, and active-account middleware. An ID selector for another resident returns an empty own-reservation collection. Caller-supplied user/barangay/facility filters do not override ownership or add unsupported constraints.

Taft administrators continue to receive records managed by current Taft facilities, even when the reservation's stored barangay is stale, plus the existing stored-barangay fallback for null facility IDs. The characterization fixture verifies their six matching rows, including another user's reservation. Super Admin continues to receive all ten fixture rows. Both roles still receive LengthAwarePaginator results; valid direction handling and last-page correction remain unchanged.

Resident filters do not gain the admin validation array. Invalid admin filters still produce the existing HTML validation redirect/session errors, including requests with JSON Accept headers because the existing exception configuration does not treat reservations.index as a JSON validation endpoint.

An array `search` retains the existing Array-to-string exception during shared controller preprocessing before admin validation. Guest, unverified, and deactivated requests with that malformed input are intercepted first by the existing middleware. This batch documents those behaviors without correcting or normalizing them.

## 7. Final verification results

| Check | Result |
| --- | --- |
| Characterization against original controller | **17 passed; 370 assertions** |
| Characterization after extraction | **17 passed; 370 assertions** |
| Before/after captured listing cases | **99 identical**, including SQL/bindings and HTML |
| Full Laravel feature/unit suite | **352 passed; 4,862 assertions; zero failures** |
| Full JavaScript suite | **36 passed; zero failures** |
| PWA private-page security tests | All 10 passed within JavaScript suite |
| Private analytics cache checks | All 32 passed |
| Password reset PWA checks | Passed |
| UI consistency browser suite | 210 page/viewport checks passed |
| Facility/Equipment cards browser suite | 20 passed |
| Facility view/gallery browser suite | 18 passed |
| Resident facility actions browser suite | 12 passed |
| Time-input layout browser suite | 9 passed |
| Shared modal browser suite | 16 passed |
| Payments browser suite | 4 passed |
| Official Use browser suite | 4 passed |
| Reservation DataTable browser suite | 28 passed |
| Analytics browser suite | 4 viewport checks passed |
| Dashboard browser suite | 10 role/viewport checks passed |
| Total existing browser checks | **335 passed across all 11 suites** |
| PHP syntax | All 94 PHP files under app/routes/tests passed |
| Laravel Pint | `--test` passed for all three new/modified application/test PHP files |
| `git diff --check` | Passed |
| Prior work preservation | Eight protected source/report files unchanged |

The resident facility-actions and shared-modal Chrome runners timed out on their first attempts. The former recorded updater access errors; the latter had captured completed DOM results but still raised the existing spawnSync timeout before its assertions could finish. Both original suites passed unchanged retries. The temporary runner/diagnostic wrapper only records output and preserves existing arguments, Chrome settings, time budgets, and assertions; it does not suppress errors or substitute results. No browser source or application change was made to obtain passing results. These transient runner failures are retained in the per-attempt logs and browser-status.json.

Verification records are in `storage/app/phase4c-check/`, notably:

- `git-status-before.txt` and `preserved-file-hashes.json`.
- `baseline-laravel.xml`, `characterization-draft.xml`, `characterization-before.xml`, `characterization-after.xml`, and `final-laravel.xml`.
- `listing-before/`, `listing-after/`, and `listing-equivalence.json`.
- JavaScript/PWA logs, per-suite browser attempt logs, and `browser-status.json`.

## 8. Remaining risks

No behavior differences were detected in the focused cases, exact source comparison, full suites, or captured SQL/data/HTML comparison.

SQLite characterization cannot independently prove MySQL collation-specific LIKE behavior, unspecified production ordering for complete sort ties, lock contention, deadlocks, or concurrent-worker behavior. Existing SQL construction and ordering are preserved exactly; no ID tie-breaker or concurrency-sensitive change was introduced. No MySQL concurrency test environment was created.

The unpaginated resident collection retains its existing scale characteristics. The malformed search exception, permissive resident filters, unusual integer casts, ignored direction/date/page inputs, unknown-status handling, and legacy snapshot behavior are existing contracts for this batch. Changing them requires separate approval; they were not silently corrected.

## 9. Recommended next batch

The next priority in the approved Phase 4A plan is **B2: basic authentication/password-reset input Form Requests**, one endpoint at a time, after dedicated input/response-precedence characterization and separate approval. Preserve exact validation rules, error bags, redirects, guest/throttle behavior, sessions, hashing, broker results, mail failure handling, and security event timing. No shared authentication service or workflow rewrite is proposed in that batch.

B2 and all subsequent batches remain unimplemented.
