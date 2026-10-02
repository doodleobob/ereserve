You are working inside my existing Laravel eReserve project.

TASK:
Convert the Admin Reservation Management table into a proper DataTable.

IMPORTANT:
Inspect the existing implementation first and preserve all existing
business logic.

This task is focused ONLY on Reservation Management.

Do NOT modify:
- Official Use
- Payments
- Notifications
- Calendar
- Analytics
- Facility Management
- User-side reservation workflow

==================================================
RESERVATION DATATABLE
==================================================

Convert the existing Reservation Management list into a clean,
responsive DataTable.

Use columns similar to:

Reservation ID
Resident
Resource
Reservation Date
Time
Total
Status
Actions

Use the actual existing database/model fields.

==================================================
DATATABLE FEATURES
==================================================

Provide:

- Search
- Status filter
- From Date
- To Date
- Sorting
- Pagination
- Rows per page

Suggested rows per page:

10
25
50
100

Keep the existing eReserve admin design.

Do not redesign the whole page.

==================================================
STATUS FILTER
==================================================

Provide:

Status: [ All Statuses ▼ ]

Use the actual reservation statuses in the project.

For example:

All
Pending
Accepted
Rejected
Cancelled

==================================================
IMPORTANT ACTION LOGIC
==================================================

Actions MUST depend on reservation status.

Use exactly this intended behavior:

PENDING
→ View
→ Accept
→ Reject

ACCEPTED
→ View
→ Edit

REJECTED
→ View

CANCELLED
→ View

Do NOT display actions that do not belong to the reservation's current
status.

==================================================
PENDING RESERVATION
==================================================

For a Pending reservation:

Actions:

⋮
View
Accept
Reject

Pending reservations must NOT have:

Edit

because the reservation has not been accepted yet.

==================================================
ACCEPT PENDING RESERVATION
==================================================

When admin clicks:

Accept

open the existing Accept/confirmation modal on the SAME Reservation
Management page.

Do NOT navigate to another page.

Preserve the project's existing acceptance and payment behavior.

Show relevant information such as:

Resident
Resource
Date
Time
Total Payment

Use the existing business logic.

Do not redesign the Payment workflow in this task.

==================================================
REJECT PENDING RESERVATION
==================================================

When admin clicks:

Reject

open a confirmation/modal on the SAME page.

Preserve the existing rejection behavior.

After successful rejection:

Pending
→ Rejected

Update the DataTable row.

Show a success toast.

Keep the admin on Reservation Management.

==================================================
ACCEPTED RESERVATION
==================================================

For an Accepted reservation:

Actions:

⋮
View
Edit

Do NOT show:

Accept
Reject

because the reservation has already been accepted.

==================================================
EDIT ACCEPTED RESERVATION
==================================================

The Edit action belongs ONLY to Accepted reservations.

When admin clicks:

Edit

open an Edit Reservation modal on the SAME Reservation Management page.

Do NOT navigate to another page.

For now, preserve the existing fields/business rules for editing an
accepted reservation.

The important behavior for this task is:

Accepted
→ View
→ Edit

Later, this Edit action can also be used when an Accepted reservation
needs to be rescheduled or cancelled because of Official Use.

Do NOT implement the new Official Use workflow yet.

==================================================
VIEW ACTION
==================================================

View should be available for all reservation statuses.

When admin clicks:

View

open the Reservation Details modal on the SAME page.

Do NOT navigate to a separate reservation details page.

Show existing reservation information such as:

Reservation ID
Resident
Resource
Date
Start Time
End Time
Purpose
Status
Total Payment

Use existing information already available in the project.

==================================================
REJECTED RESERVATION
==================================================

For:

Rejected

show only:

⋮
View

Do NOT show:

Accept
Reject
Edit

==================================================
CANCELLED RESERVATION
==================================================

For:

Cancelled

show only:

⋮
View

Do NOT show:

Accept
Reject
Edit

==================================================
ACTION MATRIX
==================================================

Implement the UI conditionally:

| Reservation Status | View | Accept | Reject | Edit |
|--------------------|------|--------|--------|------|
| Pending            | Yes  | Yes    | Yes    | No   |
| Accepted           | Yes  | No     | No     | Yes  |
| Rejected           | Yes  | No     | No     | No   |
| Cancelled          | Yes  | No     | No     | No   |

Do not simply hide buttons using JavaScript if the backend can still
perform unauthorized/invalid status transitions.

Backend validation/authorization must also enforce the appropriate
actions.

==================================================
MODAL-BASED INTERACTION
==================================================

Use modals instead of separate pages.

Expected:

Reservation Management
        ↓
DataTable
        ↓
Admin clicks ⋮
        ↓

PENDING:

View
Accept
Reject

OR

ACCEPTED:

View
Edit

        ↓
Modal opens
        ↓
Admin performs action
        ↓
AJAX/fetch if appropriate
        ↓
Laravel processes request
        ↓
JSON response
        ↓
DataTable updates
        ↓
Toast
        ↓
Admin remains on Reservation Management

Avoid unnecessary page redirects.

==================================================
DATATABLE UPDATE AFTER ACTION
==================================================

After a successful action, update the affected row.

Example:

Pending reservation:

Status:
Pending

Actions:
View
Accept
Reject

Admin accepts it.

After success:

Status:
Accepted

Actions must immediately become:

View
Edit

The admin should NOT need to manually reload the page to get the correct
actions if the current architecture can update the row dynamically.

Similarly:

Pending
→ Reject
→ Rejected

Actions become:

View

==================================================
SEARCH
==================================================

Allow searching useful fields such as:

Reservation ID
Resident
Resource
Purpose

Use existing relationships.

==================================================
SORTING
==================================================

Allow sorting appropriate columns such as:

Reservation ID
Resident
Resource
Reservation Date
Total
Status

Do NOT sort the Actions column.

==================================================
PAGINATION
==================================================

Provide pagination and rows-per-page.

Example:

Show [10 ▼] entries

Showing 1 to 10 of 42 reservations

Previous  1  2  3  4  5  Next

==================================================
RESPONSIVE DESIGN
==================================================

The DataTable must work on:

Desktop
Tablet
Mobile/PWA

Use horizontal scrolling on small screens if necessary.

Do not compress the columns until they become unreadable.

==================================================
PRESERVE BARANGAY SCOPING
==================================================

Keep existing SaaS/barangay isolation.

Barangay admins must only see reservations they are authorized to manage.

Do not expose another barangay's reservations.

==================================================
PERFORMANCE
==================================================

Avoid N+1 queries.

Eager-load relationships needed by the DataTable where appropriate.

Do not perform unnecessary queries for every table row.

==================================================
FUTURE EXPORT SUPPORT
==================================================

Structure the Reservation DataTable cleanly because PDF/CSV/Excel
report export may be added later.

BUT DO NOT implement export yet.

Do not install PDF or Excel libraries in this task.

==================================================
DO NOT IMPLEMENT OFFICIAL USE YET
==================================================

Do NOT add Official Use conflict behavior in this task.

We are establishing Reservation Management first.

Later:

Accepted
→ Edit

will be used by the admin when an accepted reservation is affected by
Official Use and needs to be rescheduled or cancelled.

For now, focus on the DataTable and correct status-based actions.

==================================================
TESTS
==================================================

Verify:

1. Reservation Management loads.
2. DataTable displays reservation records.
3. Search works.
4. Status filter works.
5. Date filtering works.
6. Sorting works.
7. Pagination works.
8. Rows-per-page works.

9. Pending shows:
   View
   Accept
   Reject

10. Pending does NOT show Edit.

11. Accepted shows:
    View
    Edit

12. Accepted does NOT show:
    Accept
    Reject

13. Rejected shows only View.

14. Cancelled shows only View.

15. View uses a modal.

16. Accept uses a modal.

17. Reject uses a modal.

18. Edit uses a modal.

19. Actions do not unnecessarily navigate to separate pages.

20. Accepting Pending changes:
    Pending → Accepted

21. After acceptance, actions become:
    View
    Edit

22. Rejecting Pending changes:
    Pending → Rejected

23. After rejection, only View remains.

24. Existing payment behavior is preserved.

25. Barangay scoping remains correct.

26. Existing Reservation tests continue passing.

==================================================
EXPECTED RESULT
==================================================

RESERVATION MANAGEMENT

Search: [________________]

Status: [ All Statuses ▼ ]

From: [ Date ]
To:   [ Date ]

Show [10 ▼] entries


---------------------------------------------------------------
ID | Resident | Resource | Date | Time | Total | Status | Actions
---------------------------------------------------------------

1  | Juan     | Chairs   | ...  | ...  | ₱100 | Pending | ⋮

Actions:
View
Accept
Reject


2  | Maria    | Court    | ...  | ...  | ₱500 | Accepted | ⋮

Actions:
View
Edit


3  | Pedro    | Chairs   | ...  | ...  | ₱100 | Rejected | ⋮

Actions:
View

---------------------------------------------------------------

==================================================
FINAL RULE
==================================================

Pending reservation:
View + Accept + Reject

Accepted reservation:
View + Edit

Rejected reservation:
View only

Cancelled reservation:
View only

All management actions should use modals on Reservation Management
instead of navigating to separate pages.

Do not change Official Use yet.
Do not change Payments yet.
Do not change unrelated features.

After implementation report:

1. Files modified
2. DataTable implementation
3. Search/filter implementation
4. Pagination implementation
5. Status-based action implementation
6. Modal implementation
7. Backend action validation
8. Tests added/updated
9. Complete test results