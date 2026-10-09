# Mobile PWA navigation drawer

Implemented on 2026-10-09 (Asia/Manila).

The authenticated mobile interface now has a compact blue header with a hamburger, eReserve branding, and the existing notification bell. Its left drawer shows the authenticated account, the existing role-specific destinations, and Logout. Desktop keeps the existing blue header and horizontal tabs.

## Files and reused components

| File | Change |
| --- | --- |
| `resources/views/components/layouts/user.blade.php` | Reuses the existing authenticated layout; adds the hamburger, drawer partial, and deferred versioned script. |
| `resources/views/partials/user-navigation.blade.php` | Extracts the original desktop links, labels, order, SVGs, and role conditions into one shared menu used by both presentations. Adds mobile SVGs for the three links that previously had no icon. |
| `resources/views/partials/logout.blade.php` | Extracts the original POST/CSRF Logout form for both locations. |
| `resources/views/partials/mobile-navigation.blade.php` | Adds the native dialog, profile, route-based active section, navigation, and footer. |
| `public/css/app.css` | Adds scoped mobile header/drawer rules, safe-area spacing, independent scrolling, focus styles, and motion preferences. |
| `public/js/mobile-navigation.js` | Implements opening/closing, scroll locking/restoration, focus management, viewport/page lifecycle handling, and coordination with existing dialogs. |
| `tests/Feature/MobileNavigationTest.php` | Verifies role-menu parity, successful existing destinations, account rendering/escaping, notification uniqueness, active sections, and Logout. |
| `tests/Feature/FrontendAssetContractsTest.php` | Includes the new deferred navigation script in the existing script-order contract. |
| `tests/Feature/SuperAdminResidentOversightTest.php` | Preserves administrator exclusion from the resident listing while allowing the signed-in account's required email in the drawer header. |
| `tests/Frontend/ui-consistency-browser.cjs` | Preserves desktop checks and exercises the new drawer on every authenticated mobile fixture. Existing business dialogs retain their own checks. |
| `tests/Frontend/mobile-navigation-browser.cjs` | Adds real Chrome keyboard, pointer, wheel, motion, viewport, notification, modal, and desktop baseline comparisons using built-in Node APIs. |
| `docs/mobile-navigation-drawer.md` | This implementation and verification report. |

The existing notification partial/script, footer, page content/asset stacks, inline SVG convention, PWA registration, and authenticated layout are reused. No dependency or frontend build system was introduced.

## Actual navigation

The following tables preserve the original menu order. URLs come from the existing Laravel routes; no destination was added.

### Resident (`user`)

| Label | Route name | URL |
| --- | --- | --- |
| Dashboard | `dashboard` | `/dashboard` |
| Facilities | `facilities` | `/facilities` |
| My Reservations | `reservations.index` | `/reservations` |
| Profile | `profile.edit` | `/profile` |

### Barangay Admin (`admin`)

| Label | Route name | URL |
| --- | --- | --- |
| Admin Dashboard | `dashboard` | `/dashboard` |
| Calendar | `calendar` | `/calendar` |
| Reservation Management | `reservations.index` | `/reservations` |
| Official Use | `official-uses.index` | `/official-uses` |
| Payments | `payments.index` | `/payments` |
| Facility Management | `facilities` | `/facilities` |
| Resident Management | `residents.index` | `/admin/residents` |
| Analytics | `analytics` | `/analytics` |
| Profile | `profile.edit` | `/profile` |

### Super Admin (`super_admin`)

| Label | Route name | URL |
| --- | --- | --- |
| Super Admin Dashboard | `dashboard` | `/dashboard` |
| Calendar | `calendar` | `/calendar` |
| Reservation Management | `reservations.index` | `/reservations` |
| Official Use | `official-uses.index` | `/official-uses` |
| Payments | `payments.index` | `/payments` |
| Facility Management | `facilities` | `/facilities` |
| Resident Management | `residents.index` | `/admin/residents` |
| Admin Management | `admins.index` | `/admins` |
| Analytics | `super-admin.analytics` | `/super-admin/analytics` |
| Profile | `profile.edit` | `/profile` |

The Super Admin menu retains its pre-existing Official Use and Facility Management destinations. Resident oversight and payment oversight retain their existing read-only policies. Tenant scopes, account management privileges, routes, middleware, controllers, and policies were not modified.

## Account and Logout

The layout's existing `auth()->user()` supplies `name`, `email`, and `barangay`. The drawer renders the same stored name used by the desktop header, with Blade escaping and a Unicode-aware uppercase first initial. It renders the registered barangay when present, including for Super Admin accounts that have one. It invents no barangay for accounts without a registered value. No image-upload feature or private profile persistence was added.

Both presentations use the same extracted form: `method="POST"`, `route('logout')`, `@csrf`, and the exact label **Logout**. The existing controller still logs out, invalidates the session, regenerates the CSRF token, and redirects to login. No GET logout route or alternate logout implementation was introduced.

## Responsive behavior and accessibility

- **900px and below:** compact header and drawer, following the existing narrow-navigation breakpoint. Drawer width is `min(84vw, 360px)` and height follows the dynamic viewport. There is no bottom navigation.
- **901px and above:** the hamburger and drawer are hidden; original desktop header, user summary, bell, Logout, tab styling, and page spacing are preserved.
- Header/drawer spacing uses device safe-area insets. Controls are at least 44px; menu links and Logout are at least 48px. Long names/emails wrap without widening the drawer.
- Native modal-dialog semantics make background content inert and place the drawer above regular page content and notification overlays. Existing reservation/account dialogs keep their separate lifecycle and scroll lock; a programmatically opened business dialog closes the drawer without taking its focus.
- The hamburger exposes an accessible name, `aria-controls`, `aria-haspopup="dialog"`, and synchronized `aria-expanded`. The dialog and navigation have accessible labels; the active destination has `aria-current="page"`.
- Opening focuses Close. Tab and Shift+Tab remain within the drawer. Closing restores focus to the hamburger where appropriate. Hidden menus cannot receive keyboard focus.
- Close, backdrop press/release, Escape, links, Logout submission, page lifecycle events, and a resize to desktop close the drawer. The background scroll position and pre-existing inline body styles are restored.
- The account/menu region scrolls independently while Close and Logout remain accessible. Slide/fade animations honor reduced-motion preferences.
- Active states follow current route names, including nested facilities/accounts routes. A resident's alternate `/calendar` URL highlights Dashboard, preserving the existing resident label and destination.
- If JavaScript or native dialog support is unavailable, the existing navigation remains available as the fallback.

## Desktop preservation evidence

Before implementation, the original stylesheet and rendered dashboards were saved under `storage/app/mobile-navigation-check`. The native Chrome suite compares element rectangles and computed background/text/font/padding/border/display styles for the header, branding, user tools, user summary, bell, Logout, navigation, every tab, and main content. All **nine** comparisons passed across three roles at **901, 1280, and 1440px**. The general UI suite also checks the desktop tabs and page/modal surfaces at its existing widths.

Resident and Super Admin 390px drawer screenshots were visually inspected. All three role screenshots are available under `storage/app/mobile-navigation-check/*-mobile.png` and use isolated fixture accounts.

## Verification

Tests and rendered fixtures use the project's existing isolated in-memory SQLite configuration. The configured application database was not used.

| Check | Result |
| --- | --- |
| Pre-implementation Laravel baseline | 380 tests passed; 5,371 assertions |
| Pre-implementation JavaScript baseline | 38 tests passed |
| Pre-implementation UI browser baseline | 210 page/viewport checks passed |
| Focused Laravel regression | 68 tests passed; 1,120 assertions before the additional resident-calendar regression |
| Full Laravel suite | 384 tests passed; 5,575 assertions, including the final resident-calendar regression |
| JavaScript suite (`node --test tests/Frontend/*.test.cjs`) | 38 passed, including private-page cache, notification, and calendar security checks |
| Password-reset PWA check | Passed fresh-request/offline/token/POST checks |
| General UI browser regression | 210 checks passed, including the final expanded drawer destination, icon, touch-target, wrapping, and active-state assertions |
| Native drawer browser | 24 role/viewport scenarios passed; 9 exact desktop comparisons passed; no JavaScript console errors |
| Shared modal/payment/Official Use/DataTable browsers | 16 + 4 + 4 + 28 = 52 checks passed |
| Resident facility actions browser | 12 scenarios passed |
| Calendar/filter browser | 20 checks passed |
| Resident reservations browser | 4 checks passed |
| Dashboard browser | Both roles passed at 1280, 1024, 768, 390, and 320px |
| PHP syntax / JavaScript syntax | Passed for changed PHP/Blade and JavaScript files |
| Laravel Pint | Passed for changed PHP files |
| `git diff --check` | Passed |

Native drawer viewports: **320×568, 390×844, 412×915, 768×700, 900×700, 901×800, 1280×900, 1440×900**, for each role. Checks cover actual Escape/Tab/Shift+Tab input, backdrop clicks, wheel scrolling, long profile values, scroll/style restoration, rapid close/reopen, normal/reduced motion, existing notification polling and unread count, existing modal coordination, link/form dismissal, and desktop resizing. Laravel tests verify the real routes and Logout backend; the browser tests suppress their fixture pages' actual network navigation.

The browser checks above total **332 passing role/page/viewport scenarios**, with the nine desktop baseline comparisons included in the native drawer suite.

Sandboxed Laravel/Pint subprocesses could not resolve the working directory, and sandboxed Chrome failed with GPU/access errors. Successful runs used approved execution outside that sandbox. One full-suite invocation initially had four fixture-output directory errors; the command was corrected to use the existing fixture directories and the full suite then passed. No assertions or security checks were weakened to accommodate those execution errors.

`public/sw.js`, the manifest, and private-page cache rules are unchanged. The new script uses a versioned public asset URL. No backend business workflow, notification implementation, database schema, or private offline storage behavior changed. Nothing was committed or pushed.

## Remaining limitations

- The attachment contained the written request; no reference screenshot was attached. Visual implementation follows its stated slide-out layout and eReserve colors.
- Browser coverage is Chrome emulation, including mobile/tablet dimensions and reduced motion. Physical iPhone Safari, Android hardware, installed-PWA safe-area behavior, and screen-reader speech still need device checks.
- Safe-area CSS is present, but the automated desktop Chrome environment cannot reproduce every device notch, virtual keyboard, or browser toolbar transition.
- The long Admin/Super Admin menu uses independent vertical scrolling, preserving its exact ordering without extra collapsible categories.
- The browser runner follows the project's existing Windows/Chrome convention. Re-running exact desktop comparisons requires the locally retained baseline artifacts; ordinary drawer checks also run without those optional comparisons.
