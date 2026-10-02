Official Use Management is implemented in the existing Laravel/Blade application, including the supplied Actions amendment. Administrators create, view, and edit resource schedules through same-page modals on `/official-uses`. Pending overlaps are cancelled; Accepted overlaps retain their status and recorded payment while residents submit preferences for the existing Accepted → Edit workflow.

The migration was applied to the configured local MySQL database. Its older Official Use schema was preserved and imported. No existing resident, reservation, payment, notification, or facility record was deleted or reseeded.

1. **Files created**

   ```text
   app/Http/Controllers/OfficialUseController.php
   app/Models/OfficialUse.php
   app/Models/OfficialUseConflict.php
   app/Support/OfficialUseScheduling.php
   database/migrations/2026_10_02_000002_create_official_uses.php
   database/migrations/2026_10_02_000003_add_official_use_conflict_resolution_history.php
   resources/views/official-uses/index.blade.php
   resources/views/official-uses/dialogs.blade.php
   resources/views/reservations/official-use-conflicts.blade.php
   tests/Feature/OfficialUseTest.php
   tests/Feature/OfficialUseEditTest.php
   tests/Frontend/official-use-browser.cjs
   tests/Frontend/official-use-fixtures.php
   OFFICIAL_USE_REPORT.md
   ```

2. **Files modified**

   ```text
   app/Http/Controllers/DashboardController.php
   app/Http/Controllers/NotificationController.php
   app/Http/Controllers/ReservationController.php
   app/Http/Controllers/ReservationPageController.php
   app/Models/Facility.php
   app/Models/Reservation.php
   app/Notifications/ReservationActivity.php
   app/Support/AdminReservationQuery.php
   app/Support/FacilityCatalog.php
   app/Support/ReservationAvailability.php
   bootstrap/app.php
   public/css/app.css
   public/js/reservation-datatable.js
   public/sw.js
   resources/views/components/layouts/user.blade.php
   resources/views/dashboard.blade.php
   resources/views/reservations/admin-dialogs.blade.php
   resources/views/reservations/admin-table.blade.php
   resources/views/reservations/index.blade.php
   routes/web.php
   tests/Feature/AdminAnalyticsTest.php
   tests/Frontend/calendar-cache.test.cjs
   tests/Frontend/reservation-datatable.test.cjs
   ```

3. **Migration/table structure.** `official_uses` contains `id`, `barangay`, `facility_id`, `date`, `start_time`, `end_time`, `purpose`, `status`, nullable `created_by`, and timestamps. Resource names, tenant names, and administrator names are obtained from existing records. `official_use_conflicts` contains the Official Use/reservation foreign keys, `resolution`, `decided_at`, `resolved_at`, and timestamps, with a unique constraint on each schedule/reservation pair. Resource/date/status and reservation/resolution indexes support lookup. Foreign keys restrict deletion of schedule/conflict history.

   The local database had an incompatible older `official_uses` table and an older `payments` table, while the repository uses `reservations.total_payment`. The migration preserves all three original Official Use rows in `legacy_official_uses`, imports two schedules and two conflict-history records, adds the missing nullable payment field, and copies recorded paid/refunded amounts only into empty snapshots. It leaves the original payment rows unchanged and replays no notifications. Cancelled or orphaned legacy schedule rows, if present, remain in the archive. Retrying import does not duplicate schedule/conflict records. Rollback restores the original Official Use table; repaired payment snapshots and transactional storage engines remain.

   Existing local MyISAM `users`, `facilities`, `reservations`, and `notifications` tables were converted to InnoDB; new Official Use tables use InnoDB. This enables the transactions and facility locks required by the workflow. SHA-256 checks over original columns verified all original data: three archived Official Use rows, two reservations, one payment, nine notifications, seven users, and two facilities.

4. **Model relationships.** `OfficialUse` belongs to `Facility` and its creator `User`, and has many `OfficialUseConflict` records. Conflicts belong to both Official Use and Reservation. Facility and Reservation expose the corresponding collections. Official Use has no resident owner or payment relationship.

5. **DataTable.** The table has six columns: Official Use ID, Resource, Schedule, Purpose, Status, Actions. The first five are sortable. Active/Conflict rows offer View and Edit in the existing three-dot menu; Cancelled historical rows offer View only. Search covers ID/resource/purpose. Resource/status/date filters, 10/25/50/100 row sizes, sorting, and pagination run on the server. Existing table styling and AJAX navigation are reused. Horizontal scrolling preserves readable columns on smaller screens.

6. **Add modal.** Add Official Use opens the existing dialog design. Resource options are scoped to the administrator; Super Admin sees all resources with tenant labels. Fetch submits to Laravel, successful saves close the modal and refresh the table with a toast, and validation errors retain entered fields. Buttons and Escape closing are blocked while saving, and duplicate submissions are suppressed. Dialog dimensions and internal scrolling fit the viewport.

   **View/Edit modals.** View displays ID, resource, date, start/end times, purpose, and status, with a generic indication for Conflict. Edit prefills resource, date, start/end, and purpose and submits PATCH through the existing fetch transport. Save updates the existing record, closes the dialog, refreshes the filtered table, and shows “Official Use updated successfully.” Validation retains entered fields. Closing discards unsaved changes. Both modals stay on the Official Use page. Resident conflict management remains in Reservation Management.

7. **Validation.** Resource/date/start/end/purpose are required. Dates use the existing future-date rule, times use `H:i`, and purpose has a 500-character limit. Tenant ownership is checked against the locked Facility. Only validated input is accepted; submitted tenant/status/creator overrides are ignored. The application's JSON exception allowlist includes the new endpoints so modal errors return JSON.

8. **Overlap validation.** Scheduling reuses `ReservationPeriod` and its strict half-open overlap rule. Adjacent intervals are allowed, equal start/end times are rejected, and an earlier end time means the next day. Official Use overlap queries include the preceding day and both Active/Conflict schedules for the same resource and tenant.

   **Transactional editing.** PATCH checks source and target tenant authorization, locks old/new facilities in ID order and the existing schedule, excludes that schedule from Official Use overlap validation, then shares creation's conflict processing. Obsolete relationships become Resolved without editing Accepted bookings or payments. All newly overlapping Pending requests are cancelled and notified; all newly overlapping Accepted bookings retain their original fields and receive new conflict records/notices. Unchanged conflicts retain resident preferences and receive no duplicate notice. Resolved pairs that overlap again reopen with a new notice, reusing the unique pair. A nullable JSON `resolution_history` column retains earlier preferences and resolution timestamps across transitions. Notification failures roll back the schedule, conflict cleanup, cancellations, history, and notices. The additive migration is applied locally.

9. **Pending handling.** Creation and editing process every overlapping Pending reservation, including tenant-scoped legacy reservations without a facility ID. Each becomes Cancelled with Official Use as its reason, cancellation timestamp, and existing-format change history. Owners receive cancellation notifications and may make another request. Pending cancellations do not create unresolved conflicts. Moving Official Use later does not reinstate cancelled requests.

10. **Accepted handling.** Every affected Accepted reservation stays Accepted with its original schedule and payment. A separate conflict record starts at Awaiting User Decision. Every affected owner receives a conflict notification. Rejected, Cancelled, adjacent, unrelated-resource, and unrelated-tenant requests are unaffected.

11. **Resident decision.** My Reservations displays the conflict schedule, resolution, Request Reschedule, and Request Cancellation. The owner-only POST endpoint records the preference and decision timestamp. It does not edit the booking, payment, or Official Use status. Repeating the same preference is idempotent. Changing preference notifies the managing barangay's administrators; resolved/non-Accepted requests cannot submit a decision.

12. **Reservation Management integration.** Existing Pending View/Accept/Reject, Accepted View/Edit, and historical View actions remain. Conflict and resolution information appears in the status cell and View/Edit dialogs. No extra admin reservation action or separate conflict-management page was added.

13. **Conflict filter.** All, Official Use Conflict, and No Conflict are integrated with the existing AJAX filter form, pagination, and sorting. The conflict option selects Accepted reservations with unresolved records and respects the administrator's existing tenant scope.

14. **Status calculation.** An Official Use is Conflict while any unresolved Accepted conflict remains, otherwise Active. Recalculation happens inside creation, Official Use editing, and Accepted editing. Resolving one of multiple conflicts keeps Conflict; resolving the final conflict changes it to Active. Moving one Official Use does not resolve another schedule's conflict with the same booking. Recalculation sends no notifications and preserves Cancelled historical status.

15. **Rescheduling.** The existing PATCH Accepted Edit endpoint validates against Official Use in addition to its existing resource/accepted-booking checks. It excludes the edited booking from accepted-booking checks, preserves Accepted/payment, and resolves all related conflicts whose intervals no longer overlap.

16. **Cancellation.** The same existing Edit endpoint requires its established confirmation and reason. Official Use is an existing reason option. Cancellation preserves the reservation, schedule, payment, and history, resolves associated conflicts, recalculates schedule status, and sends the existing cancellation notification.

17. **Payments.** Current application payment amounts remain on Reservations; no parallel Payment model or new payment/refund workflow was introduced. Rescheduling and cancellation do not change recorded payment or hourly-rate snapshots, create duplicate financial records, or record automatic refunds. Local historical payment rows remain intact, with their recorded amount restored to the current application's snapshot field.

18. **Notifications.** New cancellation/conflict/preference events extend `ReservationActivity` and the existing database notification feed. Notification links open the relevant reservation. Conflict/preference notices arise from writes, not page reloads or status recalculation. Creation, affected-record changes, and database notifications share a transaction; a notification failure rolls everything back. Administrators' preference notices retain tenant scoping and are excluded from resident feeds if an administrator's role changes.

19. **Calendar.** The existing calendar event source returns `reservation` and `official_use` event types. Both Active and Conflict Official Use remain visible, including overnight continuations into the next month. Accepted conflicts temporarily appear alongside Official Use. Managing admins/Super Admin see purpose and textual status; residents and other tenants' admins see Official Use/Resource unavailable without internal purpose, resolution, or resident information. Official Use is displayed inline, matching the calendar's existing schedule presentation. Rescheduling/cancellation updates subsequent calendar responses; there is no extra calendar or reservation-edit action.

20. **New availability.** Official Use participates in day-slot and month availability, prevents resident creation through direct POST as well as calendar selection, and blocks newly accepting/rescheduling onto those intervals. Existing accepted-booking request behavior otherwise remains. Cancelled requests do not block availability. Official Use remains visible when its resource is marked unavailable.

21. **Tenant isolation.** Official Use ownership comes from the existing Facility's barangay. Management/resources are scoped to the owning admin, with established global Super Admin access. Shared calendar selection retains the project's cross-barangay read access, while details and editing stay tenant-scoped. A resident from another barangay can receive notices and choose preferences for their own booking of a resource managed elsewhere. Facility deletion is refused when Official Use history exists. Private Official Use pages bypass PWA caching and return private/no-store headers.

22. **Tests.** Added 33 feature tests: 18 for the original Official Use workflow and 15 for View/Edit, all editable fields, self-exclusion, overnight validation, old/new/multiple conflicts, tenant and Super Admin access, preference/history preservation, notification deduplication and rollback, current calendar/availability, and existing Accepted Edit/payment behavior. Updated navigation expectations, added two frontend transport tests, extended PWA cache coverage, and added isolated SQLite browser fixtures plus Chrome checks at 1280/768/390/320 pixels.

23. **Verified results.** Full Laravel suite: **226 passed, 3,036 assertions**. Official Use feature subset: **33 passed, 560 assertions**. Frontend suite: **21 passed**. Chrome: **four viewport checks passed**, covering Add/View/Edit modal bounds, required and prefilled fields, duplicate-submit prevention, disabled controls/Escape, validation retry, PATCH of the existing record, same-page saves, toasts, refreshed Active/Conflict rows, discarded unsaved edits, sorting, and filters. Pint formatting and `git diff --check` passed. Read-only local MySQL rendering verified the current three Official Use rows, six columns and View/Edit dialogs, Reservation Management, the current calendar event, and preserved paid amount. Test and browser fixture databases use isolated in-memory SQLite. This amendment adds only the nullable history column to the application database; no live schedule or resident booking was edited for testing. The earlier import fingerprints describe verification at that migration, rather than a permanent assertion about subsequently managed application records.

No Official Use cancellation route or export feature was added. The supplied specification at `database/specs/official_use.md` was retained unchanged. One-off local schema audit, history fingerprints, and rendered browser artifacts are under ignored `storage/app/` paths.
