# Facility Management View modal

1. **Implementation found.** Admin and Super Admin already shared `facilities.blade.php`, with a same-page View dialog per authorized resource. It used a plain definition list, small photo thumbnails, and a Calendar link. The resident details page already had a reusable multiple-photo gallery with `object-fit: contain`. `FacilityCatalog` supplies every required field and the ordered photo relationship to the management page.

2. **Files modified for this request.**
   - `resources/views/facilities.blade.php`: includes the shared View partial, retaining the existing card actions.
   - `resources/views/partials/facility-view-modal.blade.php`: new read-only details layout shared by both roles.
   - `resources/views/components/layouts/user.blade.php`: loads the extracted gallery script instead of initializing the gallery inline once.
   - `public/js/facility-gallery.js`: reuses the existing carousel behavior, initializes refreshed dialogs once, and resets a modal gallery on close.
   - `public/css/app.css`: View-specific body scrolling, prominent image/name, responsive facts, and content-fitting secondary Close button.
   - `public/sw.js`: adds the gallery asset to the static cache and advances the cache version to v13.
   - `tests/Feature/FacilityViewTest.php`: new scope and rendered-content tests.
   - `tests/Frontend/facility-layout-fixtures.php`: isolated fixtures for both management roles, single/multiple/no photos, portrait/landscape images, and the resident gallery.
   - `tests/Frontend/facility-view-browser.cjs`: new rendered browser checks.
   - `tests/Frontend/facility-layout-browser.cjs`: includes the gallery script and uses the browser runner.
   - `tests/Frontend/run-browser.cjs`: reads completed Chrome results and terminates only the test's browser process, avoiding a Windows output-pipe hang.
   - `FACILITY_VIEW_REPORT.md`: this report. Prior modal/card changes remain in the workspace.

3. **Shared modal reused.** Both roles include the same `partials.facility-view-modal` inside the existing `<x-modal>` shell, using the standard large size (maximum 760px), header, close icon, backdrop, radius, shadow, button styles, and `EReserveModal` behavior. View opens and closes without navigation or requests. Its footer contains only Close; it has no editable controls, management actions, or Calendar link.

4. **Fields displayed.** Existing images, resource name, availability badge, current-use times when present, barangay, reservation access, peso-formatted hourly rate, full description, category, location, and capacity. Access comes from the existing `reservation_access` field. Capacity keeps the resident details page's existing `person`/`persons` semantics for both Facility and Equipment; no quantity model was introduced.

5. **Images.** Reuses `partials.facility-gallery` and existing catalog photo URLs/order. Landscape and portrait photos use the existing centered `object-fit: contain` styling within a consistent responsive image container. Multiple photos retain previous/next, indicators, and arrow-key navigation. Single photos have no carousel controls; absent photos use the existing facility icon. No upload/storage logic changed. The gallery continues to work on resident details and after an AJAX management refresh.

6. **Duplicate information.** When location equals the owning barangay after trimming, ignoring case, and removing an optional leading `Barangay`, one `Barangay / Location` row displays the stored location value. Thus Taft and Barangay Taft appear together once. Distinct locations remain separately labeled and fully visible. Stored data is untouched.

7. **Admin authorization.** Existing `FacilityCatalog::allForUser` limits Admin to its own barangay. The View markup uses only those authorized records. Tests confirm that selecting another barangay does not expose its resource dialogs and that its details route still returns 404. No new endpoint or authorization changes.

8. **Super Admin authorization.** Existing global visibility is retained through the same catalog and same View partial. Tests confirm access to another barangay's resource, with its actual barangay, distinct location, access, and status.

9. **Responsive behavior.** The modal fits the viewport, with a capped width and height. Only its body scrolls; header and footer stay accessible, and the shared background scroll lock remains active. Facts use three columns on desktop and stack at 640px and below. Long names/descriptions/locations wrap without overlapping badges or causing horizontal overflow. Close retains its standard height and content-fitting width. Desktop and mobile screenshots were inspected. Browser checks cover widths 1280, 768, 640, 390, 320, and 240px, including a 400px-high viewport.

10. **Tests updated.** Backend tests cover both roles, barangay isolation, shared read-only content, full descriptions, formatting, access, availability, capacity semantics, two photos, and equivalent/distinct location presentation. Browser checks cover both roles plus the resident gallery, all six resource fixtures, image orientation/containment, single/no-photo handling, current use, carousel controls, close/reset/focus, responsive layout, body scrolling, no navigation/requests during View, and gallery initialization after AJAX refresh. Existing card regression checks retain balanced View/Edit/Delete actions.

11. **Results.**
    - `php artisan test --compact --filter 'FacilityViewTest|FacilityManagementTest|ModalWorkflowTest'`: **22 passed, 235 assertions**.
    - `node --test tests/Frontend/*.test.cjs`: **22 passed**.
    - `php tests/Frontend/facility-layout-fixtures.php`: fixtures rendered using isolated in-memory SQLite.
    - `node tests/Frontend/facility-view-browser.cjs`: **18 browser scenarios passed**.
    - `node tests/Frontend/facility-layout-browser.cjs`: **20 card-layout scenarios passed**.
    - PHP Pint, JavaScript syntax checks, and `git diff --check`: **passed**.

No production schema, facility records, rates, availability rules, reservation rules, Official Use rules, payments, calendar behavior, or authorization rules were changed for this request.
