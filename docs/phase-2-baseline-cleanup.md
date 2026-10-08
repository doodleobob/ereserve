# Phase 2: Baseline verification and low-risk cleanup

Date: 2026-10-08 (Asia/Manila)

## Outcome

The cleanup removes conclusively unreachable reservation-list code, extracts small page handlers, and gives both layouts a single shared service-worker registration implementation. No intentional application behavior changes were made. The complete Laravel suite retains the same 307 passing tests and 4,086 assertions. All final JavaScript, PWA, and browser checks pass.

No reservation workflow, Official Use conflict operation, payment processing, transaction, lock, permission, route, schema, historical migration, CSS, or asset build configuration was changed. These remain outside this phase.

## Baseline and data isolation

- Initial Git status contained only the pre-existing untracked `package-lock.json`. It was preserved unchanged; nothing was staged or committed.
- PHPUnit's existing `Tests\TestCase` aborts unless the application uses the testing environment and SQLite `:memory:` without a database URL.
- Test process variables explicitly set `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, empty `DB_URL`, array cache/session/mail, and a synchronous queue.
- `APP_CONFIG_CACHE` and `APP_ROUTES_CACHE` pointed to nonexistent files under `storage/app/phase2-check`, so tests did not use or delete the application's existing cached configuration/routes.
- Existing browser fixture scripts configure in-memory SQLite before running their migrations. Photo fixtures use their own upload directory; feature upload tests use `Storage::fake('public')`.
- No destructive commands were issued against the configured application database. No real mail or application queue worker was used.
- Logs, JUnit results, and baseline fixture copies are retained under `storage/app/phase2-check`. Existing browser runners also generate their usual fixtures/profiles under `storage/app/*-check` and temporary directories.

## Baseline results and existing failures

| Check | Before cleanup |
| --- | --- |
| Laravel feature and unit suite | 307 passed, 4,086 assertions, 0 failures/errors/skips; 193.709 seconds |
| JavaScript `*.test.cjs` suite | 22 passed, 0 failures |
| Standalone private-page cache check | 32 checks passed |
| Standalone password-reset PWA check | Passed online/offline cache and POST assertions |
| Ten browser suites other than time-layout | 326 page/viewport/scenario checks passed |
| Time-layout browser suite | Existing broken fixture; failed before application edits |

Chrome initially failed to launch correctly inside the execution sandbox, reporting access-denied/GPU startup errors. The browser suites were rerun outside the sandbox; these launch failures were environmental, not application assertions. The original time-layout runner also exceeded Node's default output buffer (`ENOBUFS`). An invocation-only buffer increase exposed its underlying failure: the test read time inputs from `facility-show.blade.php`, although they had already moved to `partials/facility-reservation-form.blade.php`. Its browser frames therefore contained no matching inputs.

The first PHP run emitted a nonfatal Xdebug log permission warning. It still passed. Subsequent runs disabled Xdebug for the test process without editing PHP configuration.

## Unreachable code removed

### ReservationPageController

The admin/super-admin branch returns the paginated admin view before the resident query is built. Thus `$isAdmin` is always false in the remaining path. Removed:

- The second admin join, resident contact-field selection, and admin tenant branch.
- The admin-only resident-name search condition.
- Admin-only preset, From, and To date filtering below that return.

The resident ownership restriction, eager loading, reservation ID search, facility/purpose search, status filtering, sort order, collection return, and view configuration values remain. The active admin listing and pagination logic remain. Every import in the touched controller is still used.

### Reservation Blade view

The resident cards occur in the `@else` branch of the admin-table selection. Removed nested admin-only requester display, contact details, calculated-amount display, legacy payment correction form, acceptance/rejection controls, and duplicate facility-link branch. Simplified resident empty-state and Booked-label conditions that had a known false admin flag.

The active admin table, dialogs, payment confirmation, permission checks, resident payment display, Official Use preferences, and facility links remain.

## JavaScript and Blade changes

- `page-filters.js` contains the existing automatic submissions used by the calendar and resident reservations, plus the calendar barangay/type resource clearing and date-to-month synchronization. Existing IDs, `data-auto-submit`, query values, form actions, and native `form.submit()` behavior remain.
- The existing `facility-management.js` now also contains the facility search/category/availability filtering. The facilities page loads it for both roles. Existing photo handling remains. Barangay browsing uses a delegated change handler so the old inline-handler behavior survives AJAX replacement of the page content.
- `profile.js` closes the security confirmation containing the clicked Cancel button. The buttons retain `type="reset"`; native reset behavior is not intercepted.
- Both layouts include `partials.pwa-registration`. The shared partial contains the same supported-browser check and `/sw.js` registration on window load. Keeping this tiny registration inline avoids introducing an external script dependency for the cached offline page. Worker cache rules, cache version, paths, and install/activate logic remain unchanged.
- Blade continues to generate routes, selected values, dates, permission-dependent content, JSON payloads, and versioned asset URLs. No stylesheet or Vite change was made.

## Frontend test updates

- Calendar tests now execute the extracted file and dispatch its captured handlers; all original clearing, month, resource, and preservation assertions remain.
- Resident facility browser tests inject the existing facility script instead of extracting inline code. All original interaction assertions remain, with an additional barangay-browsing assertion after AJAX refresh.
- The UI browser harness includes the extracted calendar/profile scripts only when the rendered page references them.
- New page-handler tests verify PWA registration in both layouts, unsupported-browser behavior, independent security Cancel panels, native reset preservation, and barangay submission after replacement.
- The pre-existing time-layout fixture was repaired to read the actual shared form, resolve the optional ID prefix, fail explicitly when expected markup is missing, and provide a 16 MiB output buffer. Its input size, alignment, mobile stacking, and error-placement assertions remain unchanged.

## Verification after meaningful changes

| Stage | Result |
| --- | --- |
| Unreachable reservation cleanup | 66 relevant Laravel tests passed, 887 assertions |
| Calendar/profile/modal handler extraction | 47 relevant Laravel tests passed, 794 assertions |
| Updated handler and account JavaScript checks | Passed |
| Affected UI/facility browser regressions | 260 checks passed |
| Repaired time-layout fixture | 9 checks passed |

## Final full-suite results

| Check | After cleanup |
| --- | --- |
| Laravel feature and unit suite | 307 passed, 4,086 assertions, 0 failures/errors/skips; 67.976 seconds with Xdebug disabled |
| JavaScript `*.test.cjs` suite | 26 passed, 0 failures |
| Standalone private-page cache check | 32 checks passed |
| Standalone password-reset PWA check | Passed |
| All eleven browser suites | 335 checks passed, 0 failures |
| PHP syntax and Pint for the modified controller | Passed |
| Syntax checks for changed application/browser JavaScript | Passed |
| `git diff --check` | Passed |

Browser breakdown: UI consistency 210; facility cards 20; facility view/gallery 18; resident facility actions 12; time-layout 9; shared modals 16; payments 4; Official Use 4; reservation DataTable 28; analytics 4; dashboard 10.

Five facility fixture variants (admin, super-admin, resident, resident detail, and restricted resident) also matched their baseline main-content markup after excluding script tags, extracted event attributes, whitespace differences, and per-run CSRF tokens.

Hashes of 77 protected files match the pre-cleanup snapshot. This includes the workflow controllers, models, services, Support helpers, policies, notifications, migrations, routes, exception configuration, service worker, package files, and Vite configuration. The pre-existing untracked package lock remains unchanged.

## Exact source, test, and documentation files changed

Modified:

1. `app/Http/Controllers/ReservationPageController.php`
2. `public/js/facility-management.js`
3. `resources/views/components/layouts/auth.blade.php`
4. `resources/views/components/layouts/user.blade.php`
5. `resources/views/dashboard.blade.php`
6. `resources/views/facilities.blade.php`
7. `resources/views/partials/calendar-filters.blade.php`
8. `resources/views/partials/profile-security.blade.php`
9. `resources/views/profile.blade.php`
10. `resources/views/reservations/index.blade.php`
11. `tests/Frontend/calendar-filters.test.cjs`
12. `tests/Frontend/resident-facility-actions-browser.cjs`
13. `tests/Frontend/time-layout.cjs`
14. `tests/Frontend/ui-consistency-browser.cjs`

Added:

15. `public/js/page-filters.js`
16. `public/js/profile.js`
17. `resources/views/partials/pwa-registration.blade.php`
18. `tests/Frontend/page-handlers.test.cjs`
19. `docs/phase-2-baseline-cleanup.md`

Generated testing artifacts are separate from this source-file list. `package-lock.json` is unrelated pre-existing work and is not part of the cleanup.

## Commands and evidence

Commands were executed with the isolated process settings described above:

```powershell
php vendor/phpunit/phpunit/phpunit --colors=never --do-not-cache-result --log-junit storage/app/phase2-check/baseline-laravel.xml
php -d xdebug.mode=off -d xdebug.log= vendor/phpunit/phpunit/phpunit --colors=never --do-not-cache-result --log-junit storage/app/phase2-check/final-laravel.xml
$jsTests = @(Get-ChildItem tests/Frontend -Filter '*.test.cjs' | ForEach-Object { $_.FullName })
node --test $jsTests
node tests/Frontend/admin-analytics-cache.cjs
node tests/Frontend/password-reset-pwa.cjs
node tests/Frontend/ui-consistency-browser.cjs
node tests/Frontend/facility-layout-browser.cjs
node tests/Frontend/facility-view-browser.cjs
node tests/Frontend/resident-facility-actions-browser.cjs
node tests/Frontend/time-layout.cjs
node tests/Frontend/modal-browser-suite.cjs
node tests/Frontend/admin-analytics.cjs storage/app/phase2-check/analytics.html
node tests/Frontend/dashboard-overview.cjs storage/app/phase2-check/dashboard
```

The modal browser suite runs the shared modal, payment, Official Use, and reservation DataTable checks. Browser fixtures were regenerated using the four existing `*-fixtures.php` scripts and the existing feature-test render hooks. JUnit XML, baseline/final logs, targeted regression logs, protected-file hashes, and baseline fixture copies are retained under `storage/app/phase2-check`.

## Behavior differences and remaining recommendations

No application behavior differences were detected in the completed checks. The existing time-layout test harness is the only baseline test defect repaired; its behavioral assertions were retained.

The Phase 1 PWA cache omissions for Super Admin Analytics and the two-factor challenge remain, because this phase explicitly preserves existing PWA behavior. A separate approved behavioral correction should address those paths. Existing overlap-rule differences, status styling, mixed asset sources, catalog query growth, and larger controller/Support boundaries are also deferred.

This verification covers the existing automated suite, isolated SQLite behavior, mocked browser workflows, rendered layouts, and PWA simulations. It does not exercise production MySQL concurrency, a real SMTP provider, or installation/update of a live deployed PWA. High-risk workflow/service refactoring requires separate approval.
