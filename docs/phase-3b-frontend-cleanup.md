# Phase 3B: Blade, frontend assets, and folder cleanup

Date: 2026-10-08 (Asia/Manila)

## Outcome and scope

The frontend now groups feature views consistently, loads page assets through shared layout stacks, and reuses identical pagination/card-image markup. No UI redesign or business-workflow refactoring was performed. Existing DOM, CSS appearance, forms, hooks, asset URLs, JavaScript execution order, chart data, and PWA security were preserved in the completed checks.

The review covered all 53 Blade files present at the start: the original 52 plus the shared PWA registration added in Phase 2. There are now 55 Blade files after adding one component and one partial. Fourteen files were moved; none was discarded.

Initial Git changes from Phases 2 and 3A, their reports, and the unrelated untracked `package-lock.json` were preserved. Hash verification confirms 240 unrelated files remain unchanged. The five modified controllers differ only in view-name strings; their queries, validation, permissions, response headers, and workflows are unchanged.

## Baseline

All baseline suites were run before source changes:

| Check | Baseline |
| --- | --- |
| Laravel feature/unit suite | 307 passed, 4,086 assertions, 0 failures/errors/skips; 54.959 seconds |
| JavaScript suite | 36 passed, including Phase 3A's ten PWA security tests |
| Standalone private-page cache checks | 32 passed |
| Standalone password-reset PWA checks | Passed |
| All eleven existing browser suites | 335 checks passed |

No existing application/test failures were found. Tests and fixture generation used explicit testing settings: in-memory SQLite, empty DB URL, array mail/cache/session, synchronous queues, and isolated configuration/route-cache paths. Existing `Tests\TestCase` rejects any non-isolated test database. No actual application database records were modified.

## Folder structure

Before this phase, `accounts`, `admins`, `auth`, `dashboards`, `official-uses`, `payments`, and `reservations` had feature folders, while calendar, facilities, profile, and analytics pages lived at the root. Their feature partials were mixed into a generic `partials` directory.

The relevant structure is now:

```text
resources/views/
  accounts/                    Account list/details/status views
  admins/                      Admin creation views
  analytics/
    index.blade.php            Admin analytics page
    super-admin.blade.php      Super Admin analytics page
    partials/admin-analytics.blade.php
  auth/                        Existing authentication views
  calendar/
    index.blade.php            Reservation calendar
    filters.blade.php
  components/
    layouts/auth.blade.php
    layouts/user.blade.php
    table-pagination.blade.php Existing table contract, shared rendering
    [Existing modal, footer, icon, phone, password components]
  dashboards/                  Existing operational dashboard views
  emails/                      Standalone email templates
  facilities/
    index.blade.php
    show.blade.php
    partials/                  Gallery, forms, cards, detail modal
  official-uses/               Existing feature views
  partials/                    Shared notifications and PWA registration
  payments/                    Existing browser and PDF report views
  profile/
    edit.blade.php
    security.blade.php
  reservations/                Existing feature views
  offline.blade.php            Public offline entry point
  welcome.blade.php            Retained unused Laravel scaffold
```

The former `dashboard.blade.php` was a reservation calendar, so placing it under `calendar` clarifies its purpose. Operational dashboards remain in `dashboards`. No public route or route name changed.

## Blade moves and reference updates

All paths below are relative to `resources/views`:

| Previous path | Current path |
| --- | --- |
| `dashboard.blade.php` | `calendar/index.blade.php` |
| `partials/calendar-filters.blade.php` | `calendar/filters.blade.php` |
| `facilities.blade.php` | `facilities/index.blade.php` |
| `facility-show.blade.php` | `facilities/show.blade.php` |
| `partials/facility-card-content.blade.php` | `facilities/partials/facility-card-content.blade.php` |
| `partials/facility-form-fields.blade.php` | `facilities/partials/facility-form-fields.blade.php` |
| `partials/facility-gallery.blade.php` | `facilities/partials/facility-gallery.blade.php` |
| `partials/facility-reservation-form.blade.php` | `facilities/partials/facility-reservation-form.blade.php` |
| `partials/facility-view-modal.blade.php` | `facilities/partials/facility-view-modal.blade.php` |
| `profile.blade.php` | `profile/edit.blade.php` |
| `partials/profile-security.blade.php` | `profile/security.blade.php` |
| `analytics.blade.php` | `analytics/index.blade.php` |
| `super-admin-analytics.blade.php` | `analytics/super-admin.blade.php` |
| `partials/admin-analytics.blade.php` | `analytics/partials/admin-analytics.blade.php` |

References were mapped before moving files. Controller `view()` names, Blade includes, direct test-render calls, expected view names, and frontend fixture source paths were updated. Every literal Blade include resolves. Route names, request keys, controller data keys, and navigation active values remain unchanged. Historical phase reports retain their original snapshot paths; this table records the current locations.

## Components, partials, and layouts

- Added `components/table-pagination.blade.php` for the identical reservation, payment, and Official Use pagination markup. It preserves each page's accessible label, Previous/Next behavior, five-page window, query parameters, `aria-current`, disabled spans, CSS classes, and `data-table-link` hooks.
- Added `facilities/partials/card-image.blade.php` for the identical resident/admin card photo and fallback icon. It receives the current item explicitly and renders the same markup.
- Existing modal, gallery, facility form, account, phone, and password components remain. Account pagination intentionally retains its distinct Previous/Next interface.
- Reviewed buttons, badges, headings, filters, actions, and empty states. Their role/context differences do not justify a large generic component layer, so they remain simple markup.
- Retained anonymous component layouts. Replacing them wholesale with `@extends`/`@section`/`@yield` would add migration work without improving these existing slot-based pages.
- Added `@stack('styles')` to each layout after the main stylesheet, and `@stack('scripts')` after the page content. Feature views declare their existing assets with `@push`.

Feature styles now render in the document head. Page scripts still occur after their data/content and before the existing footer/global notification/gallery scripts. The modal manager remains the first application script. All scripts retain `defer`, their existing public URLs, and filemtime cache-busting where already present.

## JavaScript organization

No JavaScript implementation file or public script URL changed in this phase. Phase 2 already extracted small inline handlers. The shared modal manager, notifications, and gallery interactions remain shared; authentication, profile, facilities, and analytics scripts remain feature-oriented. The DataTable module continues to serve reservations, payments, and Official Use at its established URL.

Asset declarations are now collected by the shared layouts rather than scattered as executable tags inside page content. Chart JSON remains in the feature markup with its existing safe encoding and selectors, preceding Chart.js and the corresponding analytics script. Existing AJAX fragment replacement, global initialization, focus/reset, and history behavior remain intact.

The tiny shared PWA registration remains inline, as established in Phase 2. `public/sw.js`, its v15 security policy, its precache URLs, and the registration partial remain byte-for-byte unchanged.

## Asset-source findings and decisions

The authoritative runtime assets are `public/css` and `public/js`. eReserve layouts load them directly, and all application scripts have production references.

`resources/css/app.css` and the placeholder `resources/js/app.js` are not used by eReserve feature pages. They remain referenced by `vite.config.js` and the unused Laravel welcome view. Therefore they were retained rather than deleted: removing that configured scaffold would also require a separate decision about the existing build/dev commands and dependencies.

The initial stylesheet comparison identified 23 identical rule groups shared by the runtime and legacy resource stylesheet. This is documented asset-source duplication; the active UI was not switched to the older resource stylesheet. Vite configuration, package files, dependencies, build commands, and the welcome view remain unchanged. No build pipeline or framework was introduced.

## CSS improvements

All four public stylesheets and the legacy resource stylesheet were inspected. The main stylesheet received section comments and removal of 25 blocks whose selectors had no reference in any Blade view or JavaScript file. Two mixed selector lists were trimmed only to remove unused selectors. The unused verification animation and its empty media block were also removed.

Removed selector families: demo credentials; obsolete verification progress/spinner/continue/check controls; old quick-action and recent-card layouts; the old details button; and obsolete admin schedule header/filter controls.

Live declarations, specificity for retained selectors, media conditions, and rule order remain unchanged. A CSS syntax-tree comparison verified the retained declaration sequence. Potentially dynamic/legacy status selectors were retained conservatively.

The main stylesheet still contains shared and feature-specific rules. Splitting live rules into additional conditional stylesheets would require more cascade and PWA asset-loading work, so it was deferred. Analytics/dashboard CSS files remain unchanged. PDF report CSS and email inline CSS remain local to their renderers and untouched.

## Exact change list

The fourteen moves are listed above. No file was removed as unused or discarded.

New files:

1. `resources/views/components/table-pagination.blade.php`
2. `resources/views/facilities/partials/card-image.blade.php`
3. `tests/Feature/FrontendAssetContractsTest.php`
4. `docs/phase-3b-frontend-cleanup.md`

Other modified files, excluding moved views:

1. `app/Http/Controllers/AnalyticsController.php`
2. `app/Http/Controllers/DashboardController.php`
3. `app/Http/Controllers/FacilityController.php`
4. `app/Http/Controllers/ProfileController.php`
5. `app/Http/Controllers/SuperAdminAnalyticsController.php`
6. `public/css/app.css`
7. `resources/views/accounts/confirmation.blade.php`
8. `resources/views/components/layouts/auth.blade.php`
9. `resources/views/components/layouts/user.blade.php`
10. `resources/views/dashboards/operations.blade.php`
11. `resources/views/official-uses/index.blade.php`
12. `resources/views/payments/index.blade.php`
13. `resources/views/reservations/admin-table.blade.php`
14. `resources/views/reservations/index.blade.php`
15. `tests/Feature/AdminAnalyticsTest.php`
16. `tests/Feature/OvernightReservationTest.php`
17. `tests/Frontend/calendar-filters.test.cjs`
18. `tests/Frontend/time-layout.cjs`
19. `tests/Frontend/page-handlers.test.cjs`

Existing assertions were retained. Test view/source paths were updated to the moved files; the calendar source guard now recognizes the script's push block while still requiring its resident-only condition. The three new feature tests verify role-specific script order, analytics style/data ordering, and pagination filter/accessibility/AJAX contracts.

## Verification of unchanged UI and frontend contracts

Before/after fixture generation used fixed time and random seed against in-memory SQLite. A Chrome comparison checked 51 rendered page fixtures at 1280px and 390px: 102 comparisons passed.

It compared the rendered DOM, 48 computed-style properties per element, element geometry, stylesheet order, JavaScript order, and chart JSON. Only whitespace-only nodes, executable/style tags, and per-run CSRF token values were excluded from DOM comparison; assets and chart JSON were checked separately. No DOM, style, geometry, asset-order, or data differences were detected.

The existing interaction suites also passed at their original viewport ranges, covering open modals, focus/reset, form validation, field errors, filters, pagination, AJAX mutations, browser history, payment confirmations/downloads, galleries, dashboards, and analytics. No selector, ID, data attribute, action URL, route name, CSRF mechanism, or response contract was changed.

## Final regression results

| Check | Result |
| --- | --- |
| Full Laravel suite | 310 passed, 4,174 assertions, 0 failures/errors/skips; 54.281 seconds |
| Existing Laravel coverage | Original 307 tests and 4,086 assertions retained; 3 new tests add 88 assertions |
| Full JavaScript suite | 36 passed, including all ten PWA security tests |
| Standalone private-page cache check | 32 checks passed |
| Standalone password-reset PWA check | Passed |
| Eleven existing Chrome suites | 335 checks passed |
| Baseline/cleaned frontend comparisons | 102 comparisons passed across 51 fixtures |
| PHP syntax checks | 125 files passed |
| JavaScript syntax checks | 40 files passed |
| New PHP test formatting | Pint passed |
| `git diff --check` | Passed |

Incremental checks also passed: 66 view-move regressions (1,062 assertions), 91 shared-markup regressions (1,542 assertions), and the three new asset-contract tests (88 assertions).

Logs, JUnit files, source snapshots, reference/move/change manifests, CSS cleanup evidence, and frontend comparison results are under `storage/app/phase3b-check`. Browser fixtures/profiles remain in the existing testing directories. No dependency installation, application database mutation, deployment, staging, or commit was performed.

## Remaining concerns and recommended next phase

- Legacy Vite/resource scaffold remains configured but unused by application pages; removal requires an explicit build-tooling decision.
- Main CSS remains large. Safe live-style splitting should be a separate, measured change with offline/cascade verification.
- Facility catalogs still render all cards/dialogs and query current usage per item; changing that requires backend/performance work.
- Payment views still consume positional report rows, and larger controllers/Support classes retain the Phase 1 responsibility concerns.
- Verification uses isolated SQLite, synthetic rendered pages, and mocked browser mutations; it does not exercise production MySQL concurrency or real SMTP.

Recommended next phase: characterize backend workflow contracts and prepare a focused controller/service extraction proposal for review. Reservation, Official Use, payment, authentication/authorization, notification, tenant, analytics, and transaction/locking changes remain deferred until approved.
