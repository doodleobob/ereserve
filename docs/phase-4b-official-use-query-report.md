# Phase 4B: Characterization and Official Use listing query extraction

Implemented scope: B0 focused characterization tests and B1a Official Use listing query extraction. B1b through B13 remain unimplemented.

## Baseline and isolation

Before editing, `git status --short` contained only the untracked `docs/phase-4a-backend-refactoring-plan.md`. That report was preserved unchanged; its SHA-256 remains `CC3B7ECC02B80020FA8F14BBEDF241ACFCCC703E54FB78CBB3F63205A321D285`.

All Laravel test runs used `APP_ENV=testing`, SQLite `:memory:`, an empty `DB_URL`, array cache/session/mail drivers, and the synchronous queue driver. Separate, nonexistent configuration and route cache paths prevented cached application configuration from overriding these settings. The existing `Tests\TestCase` guard independently rejects a non-testing environment, a non-SQLite connection, a non-memory SQLite database, or a database URL. The guard and PHPUnit configuration were not modified.

Existing browser fixture generators were inspected and executed with the same isolated process configuration. Their migrations and workflow fixtures ran only in ephemeral in-memory databases. Facility image fixtures use their existing dedicated fixture upload directory. No development or production database was used, and no destructive database command was issued against application data.

| Baseline check, before source/test edits | Result |
| --- | --- |
| Existing relevant Laravel tests | 90 passed; 1,181 assertions |
| Existing JavaScript suite | 36 passed |
| Private analytics cache checks | 32 passed |
| Password reset PWA checks | Passed |
| Existing Official Use Chrome suite | 4 desktop/mobile checks passed |

The Laravel baseline included OfficialUseTest, OfficialUseEditTest, SaasBarangayTest, CrossBarangayReservationTest, SuperAdminResidentOversightTest, SuperAdminPaymentOversightTest, OvernightReservationTest, and PaymentNotificationTest. There were no existing test failures in the baseline that was run. The complete Laravel/browser suites were run after extraction; a complete pre-edit Laravel/browser baseline was not claimed.

## Exact project files changed or added

| File | Change |
| --- | --- |
| `app/Http/Controllers/OfficialUseController.php` | Added the query-class import and replaced index query construction with delegation. |
| `app/Queries/OfficialUses/OfficialUseQuery.php` | New listing builder, using the existing query body and final ID ordering. |
| `tests/Feature/OfficialUseListingTest.php` | 14 characterization tests; 231 assertions. |
| `tests/Unit/MoneyTest.php` | 5 focused tests; 42 assertions. |
| `tests/Unit/ReservationPeriodTest.php` | 6 focused tests; 45 assertions. |
| `docs/phase-4b-official-use-query-report.md` | This implementation and verification report. |

Only the controller is an existing tracked file modified by this batch. The pre-existing untracked Phase 4A report is not a Phase 4B change. Verification outputs, source snapshots, diagnostic scripts, and local browser fixtures are ignored artifacts under `storage/app/`; they are not application changes. No existing test file or assertion was modified or deleted.

## Characterization tests

The new tests were run against the unchanged controller before extraction and then against the extracted implementation: **25 tests and 318 assertions passed in both runs**.

OfficialUseListingTest exercises:

- The existing view, default row order, paginator metadata, eager-loaded facility relationship, and separately scoped resource choices.
- Search by purpose, current facility name, and numeric ID; whitespace trimming, existing hash/leading-zero ID forms, falsey `0`, and SQL LIKE wildcard behavior. Administrator name, facility slug, and schedule date do not become additional searchable fields.
- Combined resource/status/date/search filters, nonexistent resource IDs, the existing `all` behavior including cancelled rows, and rejection of a requested `cancelled` status filter.
- Inclusive start-date filtering, overnight start-date semantics, one-sided dates, and reversed date ranges returning an empty listing.
- Every supported sort in both directions, same-date/time ties, same-resource/purpose/status ties, and final ID ordering.
- Omitted, empty, and explicit null filter/default values, including the actual HTTP normalization behavior.
- All permitted page sizes, second-page row IDs, query parameters retained in pagination links, and an empty out-of-range requested page.
- Resident rejection before invalid-filter validation, administrator JSON validation errors and HTML error redirects, and existing guest/unverified/deactivated middleware responses.
- Stored Official Use tenant ownership, an intentionally mismatched legacy facility/tenant fixture, attempted barangay input overrides, and Super Admin global listing/resource access.
- A listing request leaving official uses, conflicts, reservations, notifications, users, and facilities unchanged, with no dispatched notifications.

MoneyTest checks exact decimal/cents conversion, current validation formats and maximum, null versus zero display, grouping/display rounding, and existing reservation amount calculation with minute-based half-up rounding, overnight duration, zero rate, and null rate. These unsaved reservation instances do not require persisted business records.

ReservationPeriodTest checks application timezone selection, same-day and overnight duration, month/year/leap-day rollover and labels, equal-time invalidity, whole-minute truncation, start-inclusive/end-exclusive containment, cross-timezone instants, strict overlap with adjacency allowed, and unchanged Carbon inputs during overlap checks.

The first draft characterization run passed 23 of 25 tests. Two new tests incorrectly expected null/empty sort inputs to throw. Inspection of the installed Laravel Request implementation and execution against the unchanged controller established that query nulls fall back to the provided sort default; HTTP empty strings are normalized to null. Those draft expectations were corrected before extraction. No application change or pre-existing assertion change was made to resolve them.

## Query class and retained controller responsibilities

`OfficialUseQuery::forRequest(Request $request): Builder` returns the existing scoped, eager-loaded, filtered, ordered Eloquent builder. It performs no validation, authorization, pagination, rendering, or writes. Its controller caller supplies the already authorized and validated request.

The delegated block contains the existing `inBarangayFor` scope, `with('facility')`, grouped purpose/name/ID search, facility/status/date predicates, correlated facility-name sort, schedule start-time sort, and final ID tie-breaker. No conditions were normalized or replaced with new rules.

The controller still executes authorization first, then its unchanged validation array, then query construction, then `paginate($request->integer('per_page', 10))->withQueryString()`, then its unchanged resource query and view response. All creation, editing, conflict decision, validation helpers, transactions, locking, and notification code remain unchanged.

A source comparison against the pre-edit controller verified the exact query-body transfer and the exact controller result obtained by only adding the import and replacing the listing query block. This also verified that every other controller method and the retained index contracts are unchanged.

## Before/after behavior comparison

Every row below has the same characterized result before and after extraction.

| Behavior | Preserved result |
| --- | --- |
| Default sorting | Schedule date descending, start time descending, ID descending. |
| Empty HTTP or explicit null sort | Existing schedule default. |
| Omitted/null/empty direction | Descending; only explicit `asc` selects ascending. |
| Search fields | Purpose, current facility name, and existing numeric ID syntax. |
| Search `0`, whitespace, and wildcards | Existing falsey/trim/LIKE interpretation. |
| Empty/null optional filters | No additional filter. |
| Status absent, empty, null, or `all` | No status restriction; existing cancelled rows remain visible. |
| Status `active` / `conflict` | Exact requested stored status. |
| Requested `cancelled` status | Existing validation error. |
| Date bounds | Inclusive `whereDate` on the stored start date. |
| Across-midnight listing | Filtered by start date, not an added overlap/end-date rule. |
| Reversed date bounds | Valid request with zero matching rows. |
| Resource ID without matching rows | Empty result; no new existence/ownership validation. |
| Administrator tenant ownership | Stored `official_uses.barangay` scope, including legacy mismatches. |
| Super Admin | Global Official Use scope and global resource selection. |
| Eager loading | Existing facility eager loading. |
| Omitted page size | 10. |
| Empty/null page size | Existing model default of 15. |
| Accepted page sizes | 10, 25, 50, 100. |
| Out-of-range page | Requested page retained with empty items. |
| Pagination URLs | Existing filter/sort parameters retained. |
| Unauthorized invalid filters | Authorization/middleware response occurs before validation. |
| Authorized invalid filters | Existing JSON 422 or HTML validation redirect. |
| Response | Same `official-uses.index` view and `uses`/`resources` data. |

As an additional rendering check, the existing Official Use fixture generator was executed in separate isolated processes using the saved original controller and the extracted controller, with the same frozen clock and random seed and separate output folders. The **empty, created/conflict, and updated HTML files were byte-for-byte identical**. Fixture source, templates, JavaScript, and CSS were unchanged.

## Authorization and tenant verification

The focused fixture contains seven rows: six with stored Taft ownership and one with Washington ownership. One Taft-owned row references a Washington facility to document the current legacy ownership rule.

- The Taft administrator receives only its six stored-owned rows and only its two Taft resource choices.
- The Washington administrator receives only its one stored-owned row and its Washington resource choice. Search by a different tenant's ID does not escape the scope.
- Super Admin receives all seven rows and all three resource choices and can filter/search across tenants.
- Caller-supplied barangay and creator parameters do not override scope or add unsupported filters.
- A resident submitting invalid filters receives 403 rather than validation errors. Authorized administrator errors, guest login redirects, verification redirects, and deactivated logout behavior remain intact.

These characterization tests passed both before and after extraction. The existing relevant authorization tests also passed within the complete Laravel suite.

## Final verification

| Check | Result |
| --- | --- |
| Full Laravel feature/unit suite | **335 passed; 4,492 assertions; zero failures** |
| Official Use feature tests within full suite | **47 passed**, including the 14 new listing tests |
| B0 tests before extraction | 25 passed; 318 assertions |
| B0 tests after extraction | 25 passed; 318 assertions |
| Full JavaScript suite | **36 passed; zero failures** |
| PWA private-page regression tests | All 10 passed within the JavaScript suite |
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
| Total existing browser checks | **335 passed across 11 suites** |
| PHP syntax | All 92 PHP files under `app`, `routes`, and `tests` passed |
| Laravel Pint | `--test` passed for all five changed/new application/test PHP files |
| `git diff --check` | Passed |
| Source equivalence | Exact query transfer and unchanged retained controller code verified |
| Official Use rendered HTML comparison | All three fixture states identical |

Several initial Chrome runs timed out before producing assertion results, including UI, gallery, modal, analytics, and a later Official Use run. Gallery passed its normal retry. All remaining affected suites passed a later diagnostic rerun. The temporary diagnostic wrapper only captured Chrome stdout/stderr and preserved the scripts' original arguments, browser settings, time budgets, and behavioral assertions. It did not suppress errors or alter test results. No test, browser driver, or application source was changed to accommodate the timeouts. These transient runner failures remain recorded separately from the final passing results.

Detailed JUnit results and local verification artifacts are under `storage/app/phase4b-check/`, including `baseline-laravel.xml`, `characterization-before.xml`, `characterization-after.xml`, `final-laravel.xml`, `source-equivalence.txt`, and `browser-diagnostics-status.json`.

## Remaining risks and next batch

No behavior differences were detected in the characterized listing cases, full existing suites, source comparison, or rendering comparison. This is a read-query extraction; application routes, schemas, role permissions, reservation/payment/Official Use mutation rules, locking order, notification timing, authentication, PWA behavior, and UI assets were not changed.

SQLite tests do not prove MySQL lock ordering, deadlock/retry behavior, or concurrent worker execution. No new MySQL concurrency environment was created, and no concurrency-sensitive code was modified. A separately authorized disposable-database harness remains necessary before future concurrency-sensitive refactoring. Production collation-specific search behavior is also not independently exercised by in-memory SQLite; the exact existing SQL construction is retained.

The existing distinction between omitted and null/empty page sizes, falsey `0` search, wildcard search, start-date-only filtering, stored tenant ownership, and empty out-of-range pages remains deliberate preservation in this batch. Any future change to those behaviors requires separate approval and test updates describing the approved behavior change.

The next recommended architecture batch is **B1b: resident reservation listing query extraction**, following the Phase 4A plan, with focused characterization first and no pagination or workflow changes. It has not been implemented and requires approval. B1b through B13 remain outside this completed batch.
