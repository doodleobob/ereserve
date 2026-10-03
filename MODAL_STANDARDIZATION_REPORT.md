Implemented `database/specs/modals.md` using the existing Payments dialogs as the reference. No dependencies, migrations, reservation/payment rules, or tenant scope were changed. The application database was not reset, reseeded, or used for test mutations.

1. **Reference modal found.** `resources/views/payments/dialogs.blade.php` supplies Payment View/Edit; `payments/details.blade.php` supplies the read-only reservation/payment information. The reference used native `<dialog>`, `facility-modal-panel`, `reservation-table-dialog`, `facility-modal-header/body/actions`, blue primary and gray secondary buttons, an 8px radius, shadow, a dark translucent backdrop, and a 600px desktop width. `public/js/reservation-datatable.js` supplied fetch POST with Laravel method spoofing, JSON errors, duplicate-submission prevention, filtered table refresh, and green/red CRUD toasts. These established styles and behavior now form the common shell; controls have 44px touch targets, field-specific accessible errors, and sticky headers/footers.

2. **All existing modal/dialog and related interaction locations discovered.**

   | Location | Existing interaction | Result |
   | --- | --- | --- |
   | `resources/views/payments/dialogs.blade.php` | Native Payment View/Edit, reference design | Shared component and transport |
   | `resources/views/payments/details.blade.php` | Read-only modal information | Reused unchanged |
   | `resources/views/reservations/admin-dialogs.blade.php` | Native View, Reject, accepted Edit with Reschedule/Cancel steps | Shared component; existing step and action rules retained |
   | `resources/views/reservations/index.blade.php` | Native payment-receipt confirmation before Accept | Shared component; existing amount/resident/schedule behavior retained |
   | `resources/views/official-uses/index.blade.php` | Native Add Official Use | Shared component and transport |
   | `resources/views/official-uses/dialogs.blade.php` | Native View/Edit Official Use | Shared component and transport |
   | `resources/views/facilities.blade.php` | Older custom Add/Edit overlays and browser `confirm()` for Delete | Native shared Add/Edit/View/Delete dialogs |
   | `resources/views/partials/facility-form-fields.blade.php` | Existing Facility/Equipment fields, image uploads, removal controls, footer | Reused; shared error summary and Cancel-first footer |
   | `resources/views/accounts/confirmation.blade.php` | Smaller native Deactivate confirmation followed by a full-page submission | Shared AJAX confirmation for Deactivate/Activate |
   | `resources/views/accounts/status-action.blade.php` | Deactivate form / immediate Activate submission | Both use contextual confirmation |
   | `resources/views/accounts/show.blade.php` | Account detail page, resident activity, status action | Reusable detail fragment loaded into a shared modal; direct route retained |
   | `resources/views/admins/create.blade.php` | Separate admin creation form | Original fields reused in shared creation modal; direct route retained |
   | `resources/views/reservations/official-use-conflicts.blade.php` | Immediate resident Reschedule/Cancellation preference submissions | Shared confirmation dialogs and AJAX refresh |
   | `resources/views/partials/profile-security.blade.php` | Inline expandable password confirmation and staged two-factor setup/code/resend/cancel forms | Kept inline in Profile; existing error bags and staged security flow retained |
   | `resources/views/partials/notifications.blade.php`, `public/js/notifications.js` | Notification panel and transient notification notices | Kept as notification UI; unrelated notification delivery unchanged |
   | Reservation/Official Use action menus, Payments download menu, analytics expandable tables | `<details>` menus and disclosure sections | Kept as menus/disclosures; they are not popup forms |

   Audited application Blade views, public/resource JavaScript and CSS, controllers, routes, and existing feature/frontend tests. No additional popup-form systems or equipment-specific CRUD pages were found; Equipment uses the existing Facility forms.

3. **Separate-page CRUD actions found.** Admin creation at `/admins/create`, admin detail at `/admins/{account}`, and resident detail/activity at `/admin/residents/{account}` were contextual candidates. `/facilities/{slug}` combines resource details, photos, availability, calendar access, and a resident reservation form. Profile, password reset, verification, login, registration, and two-factor screens are complete account/authentication workflows.

4. **Converted actions.** Admin Management now opens Create Admin and loads View Admin into shared modals. Resident Management loads account details and paginated reservation activity into a shared modal. Facility Add/Edit already had overlays; these are now native AJAX dialogs, with contextual View and Delete confirmation added. Account Deactivate/Activate and resident Official Use preference requests stay on their current page with confirmation, JSON validation, content refresh, and success toast.

5. **Full pages intentionally retained.** Dashboard, Reservations, Facilities, Official Use, Payments, Calendar, Analytics, Resident/Admin Management, and Profile remain pages. The resident facility detail/booking page retains its combined gallery, availability and booking workflow. Authentication, recovery, verification, and staged security settings remain their existing flows. Direct admin-create and account-detail URLs remain supported for navigation and fallback; the management-page actions now open modals. Resident reservation cards already display their details inline and retain that presentation.

6. **Shared Blade component/partials.** Created `resources/views/components/modal.blade.php` as `<x-modal id="..." title="..." size="small|medium|large">`. It produces the Payments shell, an accessible title and a consistent Close button. No modal component previously existed. Existing `partials/facility-form-fields` and `payments/details` were reused. Extracted the original admin fields to `admins/form-fields` and account details/activity to `accounts/details`, each reused by both the modal and direct page.

7. **Shared CSS.** Updated `public/css/app.css`, the stylesheet used by the authenticated layout. Removed the old Facility overlay styles and repeated feature-specific dialog/control rules. Common sizing is 418px/600px/760px, bounded by viewport width and dynamic viewport height. The design keeps the reference colors, radius and shadow, with scrolling inside long dialogs, sticky header/footer, readable field styling, visible errors, wrapped read-only values, and scrollable account-history tables. Background scrolling is locked while a modal is open. No per-feature modal stylesheet was added.

8. **Shared JavaScript.** Created `public/js/modals.js` for delegated opens/closes, native Escape/backdrop behavior, focus restoration, immediate scroll unlocking, form reset, errors, saving indicators, disabled controls, duplicate-submit prevention, JSON transport, and CRUD toasts. Field errors match Laravel dot notation, including uploaded-photo errors, with `aria-invalid` and `aria-describedby`; values/files remain on failure. Failed session/network responses keep the form open. Successful saves close and refresh current content; refresh failures explicitly report that the save already succeeded. DataTables reuse this transport while retaining filter/sort/pagination/export behavior and their existing refresh logic. Accepted reservation steps listen to the common reset event. The layout loads the common script before feature scripts.

9. **Facility Management.** Add/Edit use the original name, category, description, location, capacity, status, reservation-access, hourly-rate, photo-upload, and photo-removal fields. Existing controller validation, storage cleanup, four-photo limit, authorization, and catalog operations remain. Controllers additionally return JSON for AJAX requests while retaining ordinary redirects. A delegated photo script supports previews, checks retained-plus-selected photo counts, revokes preview URLs, and works after refresh. Add/Edit/Delete refresh the management cards; View is read-only and includes the existing photos, availability, ownership/access information, and Calendar link. Delete requires the shared confirmation and invokes the existing endpoint only after submission.

10. **Reservation Management.** View, Reject, Accept/payment confirmation, and accepted Edit now use the common shell. Reschedule/Cancel choice panels, confirmation fields, amounts, cancellation reasons, and conditional fieldsets retain their behavior. Reservation statuses, `Reservation.total`, recorded payments, notifications, overnight schedules, and history rules are unchanged. Added contextual confirmations for resident Official Use preferences; recording a cancellation preference still leaves the reservation accepted for the administrator to process.

11. **Payments.** View/Edit use `<x-modal>` and the common fetch/error/toast behavior. The existing details partial, allowed statuses, receipt confirmation, immutable reservation information, table filters/summaries, and report download behavior remain. No payment controller, model, query, export, or financial rule was changed.

12. **Official Use.** Add/View/Edit use the common shell and control/error styles. Resource, date, start/end, purpose, conflict notices, and conditional Edit availability remain. Existing scheduling, conflict creation, pending cancellation, accepted-resident notification, and rescheduling services were not changed.

13. **User/Admin Management.** Admin creation reuses all original fields and validation, including phone number, assigned barangay, confirmed password, fixed admin role, and the verification event. JSON responses distinguish successful creation from creation with a failed verification email so the user is not encouraged to create the same account again. View loads the existing authorized show route; resident activity pagination refreshes within the dialog. Deactivate and Activate both confirm the selected account before the existing status endpoint runs. Status responses support AJAX without changing managed-role or barangay queries. Status buttons require JavaScript before confirmation. Loaded content uses text-safe rendering/escaped Blade output and offers retry on failed detail loads.

14. **Other interactions/PWA.** Replaced Facility browser confirmation and added resident conflict preference confirmations. Inline security and notification interactions were retained for the reasons above. Updated the service worker cache version and common modal assets; Facility routes now bypass page caching alongside the existing private management routes. This prevents stale management HTML from becoming an AJAX refresh result.

15. **Files created.**

   ```text
   MODAL_STANDARDIZATION_REPORT.md
   public/js/modals.js
   public/js/facility-management.js
   resources/views/components/modal.blade.php
   resources/views/admins/form-fields.blade.php
   resources/views/accounts/details.blade.php
   tests/Feature/ModalWorkflowTest.php
   tests/Frontend/modals-browser.cjs
   tests/Frontend/modal-browser-suite.cjs
   ```

16. **Files modified.**

   ```text
   app/Http/Controllers/AdminController.php
   app/Http/Controllers/Concerns/ManagesAccounts.php
   app/Http/Controllers/FacilityController.php
   bootstrap/app.php
   public/css/app.css
   public/js/account-management.js
   public/js/payment-confirmation.js
   public/js/reservation-datatable.js
   public/sw.js
   resources/views/accounts/confirmation.blade.php
   resources/views/accounts/index.blade.php
   resources/views/accounts/pagination.blade.php
   resources/views/accounts/show.blade.php
   resources/views/accounts/status-action.blade.php
   resources/views/admins/create.blade.php
   resources/views/components/layouts/user.blade.php
   resources/views/facilities.blade.php
   resources/views/official-uses/dialogs.blade.php
   resources/views/official-uses/index.blade.php
   resources/views/partials/facility-form-fields.blade.php
   resources/views/payments/dialogs.blade.php
   resources/views/reservations/admin-dialogs.blade.php
   resources/views/reservations/index.blade.php
   resources/views/reservations/official-use-conflicts.blade.php
   tests/Frontend/account-management.test.cjs
   tests/Frontend/calendar-cache.test.cjs
   tests/Frontend/official-use-browser.cjs
   tests/Frontend/payment-browser.cjs
   tests/Frontend/reservation-datatable-browser.cjs
   tests/Frontend/reservation-datatable.test.cjs
   ```

   The supplied `database/specs/modals.md` was not changed. Fixture HTML, reports, browser profiles, and temporary implementation/test helpers are under ignored `storage/app/` paths.

17. **Tests added/updated.** Added seven Laravel feature tests for Facility JSON creation/photo replacement/validation/authorization/deletion/rendering, admin field validation/creation/email failure, scoped account status/details/history, and resident conflict decisions preserving booking/payment rules. Updated DataTable frontend tests to exercise the actual common transport. Updated account tests for both confirmation directions and blocking actions during saves, payment/reservation/Official Use browser harnesses for the shared component/script, and PWA cache tests for Facility/account routes. Added a browser suite for Facility, Resident, Admin, and resident conflict workflows at 320/390/768/1280px; phone-height scenarios use 568px. It checks bounds, table scrolling, header/footer access, scroll locking, focus, cancel/reset, upload retention, nested validation errors, disabled/loading states, duplicate submission, session failure, same-page refresh, detail pagination, and save/refresh failure distinction.

18. **Full verification results.**

   | Check | Result |
   | --- | --- |
   | `php artisan test --compact` | 253 passed, 3,447 assertions |
   | Targeted ModalWorkflow + ReservationDataTable tests | 12 passed, 192 assertions |
   | `node --test tests/Frontend/*.test.cjs` | 22 passed |
   | Shared Facility/Resident/Admin/conflict Chrome scenarios | 16 passed |
   | Payment Chrome scenarios | 4 passed |
   | Official Use Chrome scenarios | 4 passed |
   | Reservation DataTable Chrome scenarios | 28 passed |
   | Combined Chrome scenarios | 52 passed |
   | Laravel Pint | Changed PHP files formatted successfully |
   | `git diff --check` | Passed |

   Laravel tests and fixture generation use isolated in-memory SQLite and fake upload storage. Browser checks use actual Laravel-rendered markup with mocked fetch responses; backend mutations are covered separately by Laravel tests. Responsive checks use desktop headless Chrome at the listed viewport sizes, rather than physical PWA devices. The authenticated layout serves static public CSS/JavaScript directly, so no npm build or new framework was required.
