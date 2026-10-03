You are working inside my existing Laravel project:

eReserve: A Cloud-Based Public Facility Reservation and Resource
Utilization Management System.

I now want to improve the existing:

PAYMENTS MANAGEMENT

Convert the Payments page into a proper DataTable and add downloadable
payment reports.

The admin must also be able to View and Edit Payment records.

==================================================
IMPORTANT — INSPECT THE EXISTING PROJECT FIRST
==================================================

Before modifying anything:

1. Inspect the existing Payment model.
2. Inspect the payments migration/table.
3. Inspect the Reservation model.
4. Inspect the Reservation → Payment relationship.
5. Inspect how Payment records are currently created.
6. Inspect how Reservation.total is calculated and stored.
7. Inspect the current Payments controller.
8. Inspect existing Payment routes.
9. Inspect the current Payments Blade page.
10. Inspect Reservation Management DataTable styling.
11. Inspect Official Use DataTable styling if already implemented.
12. Inspect existing DataTable libraries/dependencies.
13. Inspect existing modal implementation.
14. Inspect existing toast/notification UI.
15. Inspect existing AJAX/fetch patterns.
16. Inspect current authentication/authorization.
17. Inspect barangay/SaaS scoping.
18. Inspect Super Admin behavior.
19. Inspect existing tests.
20. Inspect composer.json and package.json.
21. Check whether PDF, Excel, or CSV export packages already exist.

IMPORTANT:

Do NOT immediately install new packages.

Reuse existing dependencies where possible.

Do NOT rebuild working Payment functionality unnecessarily.

Do NOT reset or reseed the database.

Do NOT remove existing data.

Do NOT rename database fields without first inspecting where they are
used.

Preserve the existing Laravel architecture and coding style.

==================================================
GOAL
==================================================

Create a professional Payments Management DataTable that allows the
admin to:

- View Payment records
- Search Payment records
- Filter Payment records
- Sort Payment records
- Paginate Payment records
- View Payment details
- Edit Payment status/details where appropriate
- Download filtered Payment reports as PDF
- Download filtered Payment reports as Excel
- Download filtered Payment reports as CSV

The Payments page should be suitable for administrative reporting.

==================================================
IMPORTANT FINANCIAL FIELD RULE
==================================================

The existing Reservation uses:

total

for the calculated reservation charge.

Therefore:

USE:

Reservation.total

DO NOT rename it to:

amount

DO NOT introduce:

Payment.amount

merely to duplicate Reservation.total.

Throughout the Payments UI and reports use the terminology:

TOTAL

not:

AMOUNT

==================================================
SOURCE OF TRUTH
==================================================

The source of truth for the reservation charge is:

Reservation.total

Conceptually:

$payment->reservation->total

Use the actual existing model relationship.

Do NOT duplicate the Reservation total inside the Payment table unless
the current project already intentionally stores a financial snapshot.

IMPORTANT:

If payments.amount already exists in the current database:

DO NOT immediately delete it.

First inspect:

- migrations
- model
- controllers
- tests
- Blade files
- existing business logic

Determine whether it is currently required.

Do not perform a destructive migration without understanding its use.

For the Payments DataTable/report requested here, display the existing:

Reservation.total

as:

Total

==================================================
PAYMENT BUSINESS RULE
==================================================

Payment belongs to a Reservation.

Reservation contains the booking information.

Payment contains payment-related information/status.

Keep their responsibilities separate.

RESERVATION:

- Resident
- Resource
- Reservation Date
- Start Time
- End Time
- Total
- Reservation Status

PAYMENT:

- Payment record
- Payment Status
- Payment-specific metadata already supported by the project

Do NOT move reservation information into the Payment table just to build
the DataTable.

Use relationships.

==================================================
OFFICIAL USE
==================================================

Official Use has:

NO PAYMENT.

Do NOT create Payment records for Official Use.

Do NOT include Official Use in:

- Payments DataTable
- Payment totals
- PDF report
- Excel report
- CSV report

Payments are for resident Reservation records.

==================================================
PAYMENTS PAGE
==================================================

Create/improve the admin Payments page.

Title:

Payments

Suggested subtitle:

View and manage reservation payment records.

Keep the page visually consistent with:

- Reservation Management
- Official Use
- Admin dashboard

==================================================
PAYMENTS PAGE LAYOUT
==================================================

Suggested structure:

PAYMENTS

View and manage reservation payment records.


Search:
[____________________________]


Payment Status:
[ All Statuses ▼ ]


From:
[ Date ]


To:
[ Date ]


[ Download ▼ ]


Show:
[ 10 ▼ ] entries


PAYMENTS DATATABLE

==================================================
PAYMENTS DATATABLE COLUMNS
==================================================

Use:

Payment ID
Reservation ID
Resident
Resource
Reservation Date
Total
Payment Status
Actions

Example:

-------------------------------------------------------------------------
Payment | Reservation | Resident | Resource | Date   | Total   | Status
-------------------------------------------------------------------------
P001    | R015        | Juan     | Court    | Oct 10 | ₱500.00 | Paid
P002    | R018        | Maria    | Chairs   | Oct 12 | ₱300.00 | Paid
-------------------------------------------------------------------------

Actions:

⋮

View
Edit

==================================================
PAYMENT ID / RESERVATION ID
==================================================

Use the project's existing IDs.

If the UI already formats IDs such as:

PAY-001
RES-015

reuse that display format.

Do NOT create unnecessary database columns solely to generate pretty IDs
unless the current architecture already uses them.

==================================================
RESIDENT
==================================================

Resident should come through the Reservation relationship.

Use the existing user/resident relationship.

Do NOT duplicate Resident name in Payment.

==================================================
RESOURCE
==================================================

Resource should come through the Reservation relationship.

Use the existing Facility/Resource model.

Do NOT duplicate Resource name in Payment.

==================================================
RESERVATION DATE
==================================================

Display the reservation's actual scheduled date.

Do not confuse it with:

Payment created_at

The table column is:

Reservation Date

==================================================
TOTAL
==================================================

Display:

Reservation.total

Format in Philippine Peso.

Examples:

₱500.00

₱1,250.00

₱10,000.00

Keep the underlying numeric value numeric.

Do not store formatted currency strings in the database.

==================================================
PAYMENT STATUS
==================================================

Use the actual Payment statuses supported by the current project.

For example, if currently supported:

Paid
Refunded

use those.

Do NOT invent statuses before inspecting the existing Payment model.

Display Payment Status using badges consistent with the rest of eReserve.

==================================================
ACTIONS
==================================================

Payment Actions must be:

View
Edit

Do NOT add:

Delete

Payment records are financial/history records.

They must not be casually deleted.

==================================================
VIEW PAYMENT
==================================================

When admin clicks:

View

open a modal on the SAME Payments page.

Do NOT navigate to another page.

Reuse the existing eReserve modal style.

==================================================
VIEW PAYMENT MODAL
==================================================

Show:

Payment ID
Reservation ID
Resident
Resource
Reservation Date
Reservation Time
Total
Payment Status

Example:

------------------------------------------------

Payment Details

Payment ID:
PAY-001

Reservation ID:
RES-015

Resident:
Juan Dela Cruz

Resource:
Covered Court

Reservation Date:
October 10, 2026

Reservation Time:
1:00 PM – 5:00 PM

Total:
₱500.00

Payment Status:
Paid


[ Close ]

------------------------------------------------

Use:

Reservation.total

for Total.

==================================================
EDIT PAYMENT
==================================================

The admin must also be able to:

Edit Payment

Click:

Payments
→ Actions
→ Edit

Open an Edit Payment modal on the SAME Payments page.

Do NOT navigate to another page.

==================================================
EDIT PAYMENT MODAL
==================================================

Example:

------------------------------------------------

Edit Payment

Payment ID:
PAY-001

Reservation ID:
RES-015

Resident:
Juan Dela Cruz

Resource:
Covered Court

Reservation Date:
October 10, 2026

Reservation Time:
1:00 PM – 5:00 PM

Total:
₱500.00


Payment Status:

[ Paid ▼ ]


[ Cancel ] [ Save Changes ]

------------------------------------------------

==================================================
EDITABLE PAYMENT FIELDS
==================================================

Inspect the existing Payment model first.

Allow the admin to edit fields that ACTUALLY belong to Payment.

At minimum:

Payment Status

If other legitimate Payment fields already exist and are intentionally
editable, preserve them according to the existing business rules.

Do NOT invent unnecessary fields.

==================================================
READ-ONLY RESERVATION INFORMATION
==================================================

Inside Edit Payment, these should be read-only:

Payment ID
Reservation ID
Resident
Resource
Reservation Date
Start Time
End Time
Total

The admin should NOT modify the Reservation from the Payments page.

==================================================
DO NOT EDIT RESERVATION TOTAL FROM PAYMENTS
==================================================

Reservation.total belongs to the Reservation.

Therefore:

Do NOT allow Payment Edit to change:

Reservation.total

If Reservation total needs to change, that belongs to Reservation
Management/business logic.

Payments should only display:

Reservation.total

==================================================
PAYMENT STATUS EDIT
==================================================

Example:

Before:

Payment Status:
Paid

Admin edits:

Paid
→ Refunded

After successful save:

Payment Status:
Refunded

Update the DataTable row without unnecessary navigation.

==================================================
IMPORTANT — PAYMENT STATUS VS RESERVATION STATUS
==================================================

Changing Payment Status must NOT automatically change Reservation Status.

Example:

Reservation:

Status:
Cancelled

Payment:

Status:
Paid

Later the admin records an actual refund:

Payment:

Paid → Refunded

Reservation remains:

Cancelled

Do NOT modify the Reservation again.

==================================================
CANCELLATION DOES NOT AUTOMATICALLY MEAN REFUND
==================================================

This rule is important.

Reservation:

Cancelled

does NOT automatically mean:

Payment:
Refunded

There may be a valid temporary state:

Reservation:
Cancelled

Payment:
Paid

because the actual refund has not yet occurred.

Only record:

Refunded

when an actual refund is processed/recorded according to the Payment
workflow.

==================================================
RESCHEDULING
==================================================

If an Accepted Reservation is rescheduled:

Preserve the existing Payment.

Do NOT create another Payment simply because the reservation date/time
changed.

==================================================
OFFICIAL USE CONFLICT
==================================================

If Official Use causes an Accepted reservation to be rescheduled:

Preserve the existing Payment.

Do NOT create another Payment.

If Official Use causes an Accepted reservation to be cancelled:

Preserve Payment history.

Do NOT automatically mark:

Refunded

unless an actual refund occurs.

==================================================
PAYMENT HISTORY
==================================================

Never delete the Payment merely because:

- Reservation was rescheduled
- Reservation was cancelled
- Official Use caused a conflict
- Official Use caused a reschedule
- Official Use caused a cancellation

Preserve historical financial records.

==================================================
SAME-PAGE PAYMENT EDIT
==================================================

Expected workflow:

Payments
    ↓
DataTable
    ↓
Actions
    ↓
Edit
    ↓
Edit Payment Modal
    ↓
Change Payment Status
    ↓
Save Changes
    ↓
AJAX/fetch
    ↓
Laravel validates
    ↓
Update Payment
    ↓
Return JSON
    ↓
Close modal
    ↓
Update DataTable row
    ↓
Show toast
    ↓
Remain on Payments page

Reuse the existing AJAX/fetch pattern if one exists.

==================================================
EDIT VALIDATION
==================================================

Validate on the backend.

Check:

- Payment exists
- Admin is authenticated
- Admin is authorized
- Payment belongs to authorized barangay
- Payment Status is valid
- Requested status transition is valid according to existing business
  rules

Do NOT rely only on frontend dropdown restrictions.

==================================================
EDIT SUCCESS MESSAGE
==================================================

After successful edit:

"Payment updated successfully."

==================================================
SEARCH
==================================================

Add:

Search

Search should support useful fields such as:

Payment ID
Reservation ID
Resident
Resource

Use the actual relationships.

Avoid inefficient per-row database queries.

==================================================
PAYMENT STATUS FILTER
==================================================

Add:

Payment Status

Options should reflect the actual Payment statuses.

Example:

All Statuses
Paid
Refunded

Do not hard-code statuses that do not exist.

==================================================
DATE FILTER
==================================================

Add:

From Date

To Date

The date filter should have a clearly defined meaning.

For this Payments report, inspect the existing Payment structure and use
the appropriate Payment/report date.

If the project has an actual payment transaction date, use it.

If the existing simplified Payment structure only has:

created_at

and that represents when the Payment record was recorded, use it
consistently.

Do NOT create a fake:

paid_at

field only for the DataTable unless the existing Payment design actually
needs it.

==================================================
DATE VALIDATION
==================================================

Validate:

From Date <= To Date

Handle:

Only From Date
Only To Date
Both
Neither

cleanly.

==================================================
ROWS PER PAGE
==================================================

Provide:

10
25
50
100

rows per page.

==================================================
SORTING
==================================================

Allow sensible sorting where supported.

Examples:

Payment ID
Reservation ID
Reservation Date
Total
Payment Status

Do not expose unsafe arbitrary SQL sorting.

Whitelist sortable columns.

==================================================
PAGINATION
==================================================

Use efficient pagination.

Do NOT load every Payment record into the browser unnecessarily.

If Reservation Management uses server-side Laravel pagination/filtering,
follow the same architecture.

==================================================
AVOID N+1 QUERIES
==================================================

Payment rows need related:

Reservation
Resident
Resource

Use eager loading or appropriate joins.

Do NOT run separate relationship queries for every Payment row.

==================================================
RESPONSIVE DATATABLE
==================================================

Payments DataTable must work on:

Desktop
Tablet
Mobile/PWA

On smaller screens:

allow horizontal scrolling when necessary.

Do not make columns unreadably narrow.

==================================================
FILTERED SUMMARY
==================================================

Add a useful summary for the currently filtered Payment dataset.

Show:

Total Payments
Total

Example:

Total Payments:
32

Total:
₱25,750.00

IMPORTANT:

Total should be calculated using:

Reservation.total

for the Payment records matching the active filters.

==================================================
FILTERED TOTAL VS CURRENT PAGE
==================================================

Do NOT calculate the summary using only the currently visible page.

Example:

125 Payment records match the filters.

DataTable currently shows:

10

The summary should still represent:

125 matching Payment records.

Not just the visible 10.

==================================================
DOWNLOAD / EXPORT
==================================================

Add:

[ Download ▼ ]

above the Payments DataTable.

Options:

PDF
Excel
CSV

==================================================
EXPORT RULE
==================================================

Exports must respect the CURRENT:

Search
Payment Status
From Date
To Date

The downloaded report must contain the same dataset represented by the
active filtering criteria.

==================================================
IMPORTANT — EXPORT ALL FILTERED RECORDS
==================================================

Pagination must NOT limit the export.

Example:

Filters produce:

125 Payment records

DataTable page shows:

10 records

Download PDF

PDF must contain:

ALL 125 matching records.

Do NOT export only the current visible page.

==================================================
SHARED FILTER QUERY
==================================================

Avoid duplicating filtering logic.

Prefer a reusable Payment query/service/scope.

Conceptually:

Payment Query
    ↓
Authorization / Barangay Scope
    ↓
Search
    ↓
Payment Status
    ↓
From Date
    ↓
To Date

Use the same filtering logic for:

- DataTable
- Summary
- PDF
- Excel
- CSV

This prevents inconsistent reports.

==================================================
PDF EXPORT
==================================================

Generate a professional Payment Report.

Do NOT export a screenshot of the Payments page.

The PDF should be a proper report.

==================================================
PDF HEADER
==================================================

Suggested:

eReserve

Payment Report

Barangay:
[Current Barangay]

Report Period:
[From Date] – [To Date]

Payment Status:
[Selected Status]

Generated:
[Date and Time]

Use the actual current barangay/tenant dynamically.

Do NOT hard-code:

Barangay Washington

because eReserve supports barangay/SaaS scoping.

==================================================
PDF TABLE
==================================================

Use columns:

Payment ID
Reservation ID
Resident
Resource
Reservation Date
Total
Payment Status

Do NOT include:

Actions

Do NOT include:

View
Edit
buttons
icons
HTML badges

==================================================
PDF TOTAL
==================================================

At the end of the PDF show:

Total Payments:
[number]

Total:
₱[sum]

Example:

Total Payments:
32

Total:
₱25,750.00

Calculate using only the filtered records included in the report.

==================================================
PDF LAYOUT
==================================================

Use a clean printable report layout.

Landscape orientation is recommended because of the number of columns.

Use:

Readable font sizes
Clear headings
Clean table borders/spacing
Page numbers if supported

Do NOT include:

Admin sidebar
Navigation
DataTable search controls
Buttons
Modals

==================================================
PDF MULTIPLE PAGES
==================================================

If the report contains many Payment records:

Allow the table to continue across multiple pages.

Repeat the table header where supported.

Do not cut rows awkwardly if avoidable.

==================================================
EXCEL EXPORT
==================================================

Excel should contain clean spreadsheet data.

Columns:

Payment ID
Reservation ID
Resident
Resource
Reservation Date
Total
Payment Status

Do NOT export:

Actions
HTML
Badges
Icons

==================================================
EXCEL TOTAL
==================================================

Where appropriate, include:

Total Payments

and:

Total

either in a report header/summary or final summary row.

Ensure the Total values are numeric where possible so Excel can perform
calculations.

==================================================
CSV EXPORT
==================================================

CSV should contain clean raw tabular data.

Columns:

Payment ID
Reservation ID
Resident
Resource
Reservation Date
Total
Payment Status

Do NOT include:

Actions
HTML
icons
buttons

==================================================
EXPORT TERMINOLOGY
==================================================

Every report must use:

Total

NOT:

Amount

because the financial charge is:

Reservation.total

==================================================
EXPORT AFTER PAYMENT EDIT
==================================================

Exports must always use current database information.

Example:

Payment originally:

Paid

Admin edits:

Refunded

New PDF/Excel/CSV export should show:

Refunded

Do not export stale status data.

==================================================
EXPORT FILE NAMES
==================================================

Use clear file names.

Examples:

ereserve-payment-report-2026-10-02.pdf

ereserve-payment-report-2026-10-02.xlsx

ereserve-payment-report-2026-10-02.csv

If useful, include the report period.

Avoid random/unreadable file names.

==================================================
EXPORT AUTHORIZATION
==================================================

Export endpoints must require authorization.

Do NOT rely only on hiding the Download button.

Residents must NOT be able to manually access admin Payment export
routes.

==================================================
BARANGAY / SAAS SCOPING
==================================================

This is critical.

Barangay A admin:

can only view/export Payment records belonging to Barangay A
reservations.

Barangay A admin must NOT see/export:

Barangay B Payments.

Apply scoping in:

DataTable query
Summary query
View
Edit
PDF export
Excel export
CSV export

==================================================
DO NOT TRUST CLIENT BARANGAY ID
==================================================

Do NOT allow:

?barangay_id=2

to bypass tenant isolation.

Determine the authorized barangay/tenant using the authenticated admin
and the existing project architecture.

Preserve Super Admin behavior according to current rules.

==================================================
EXPORT VALIDATION
==================================================

Validate export parameters.

Validate:

Search
Status
From Date
To Date
Format

Reject unsupported formats.

Do not allow arbitrary:

file paths
classes
templates

through request parameters.

==================================================
EMPTY RESULTS
==================================================

If filters return no Payments:

DataTable should show a clean empty state.

Example:

"No payment records found."

Do not show broken pagination.

For export:

Handle empty report results cleanly.

Do not generate malformed files.

==================================================
LOADING STATES
==================================================

For DataTable filtering and Payment Edit:

show appropriate loading state if the existing UI supports it.

For report generation:

prevent repeated clicks while generating/downloading.

Do not create duplicate requests unnecessarily.

==================================================
DOWNLOAD BUTTON UX
==================================================

Suggested:

[ Download ▼ ]

PDF
Excel
CSV

Keep it consistent with existing admin controls.

Do not clutter the DataTable with separate large export buttons if a
dropdown fits the current UI better.

==================================================
NO DELETE
==================================================

Final Payment actions:

View
Edit

ONLY.

Do NOT add:

Delete

==================================================
DO NOT MODIFY RESERVATION FROM PAYMENTS
==================================================

The Payments page must NOT be used to:

Accept Reservation
Reject Reservation
Reschedule Reservation
Cancel Reservation
Change Resource
Change Reservation Date
Change Start Time
Change End Time
Change Reservation.total

Those belong in Reservation Management.

==================================================
DATA CONSISTENCY
==================================================

Payment information displayed in:

DataTable
View Modal
Edit Modal
PDF
Excel
CSV

should all come from the same current database relationships.

Avoid duplicated values that can become inconsistent.

==================================================
PERFORMANCE
==================================================

Keep the page efficient.

Use:

Pagination
Eager loading
Scoped queries
Filtered database queries

Avoid:

N+1 queries
Loading the entire database for normal page display
Repeated identical relationship queries

For large exports, use an appropriate Laravel export approach.

==================================================
DEPENDENCIES
==================================================

Inspect:

composer.json
package.json

before adding anything.

For PDF:

Reuse an existing PDF package if available.

For Excel/CSV:

Reuse an existing spreadsheet/export package if available.

If a new package is genuinely necessary:

Choose a stable Laravel-compatible package.

Install only what is necessary.

Do NOT replace an existing working export library with another one
without reason.

==================================================
UI CONSISTENCY
==================================================

Match the existing eReserve admin UI.

Reuse:

Card styles
Table styles
Buttons
Dropdowns
Badges
Modals
Form controls
Spacing
Typography
Toast messages

Do not make Payments look like a separate application.

==================================================
EXPECTED PAYMENTS PAGE
==================================================

PAYMENTS

View and manage reservation payment records.


Search:
[__________________________]


Payment Status:
[ All Statuses ▼ ]


From:
[ Date ]


To:
[ Date ]


[ Download ▼ ]
    PDF
    Excel
    CSV


Total Payments:
32

Total:
₱25,750.00


Show:
[ 10 ▼ ] entries


---------------------------------------------------------------------------
Payment | Reservation | Resident | Resource | Date | Total | Status | Action
---------------------------------------------------------------------------
P001    | R015        | Juan     | Court    | ...  | ₱500  | Paid   | ⋮
P002    | R018        | Maria    | Chairs   | ...  | ₱300  | Paid   | ⋮
---------------------------------------------------------------------------


Actions:

View
Edit

==================================================
EXPECTED VIEW WORKFLOW
==================================================

Payments
    ↓
Actions
    ↓
View
    ↓
Payment Details Modal
    ↓
Read-only Payment/Reservation information
    ↓
Close
    ↓
Remain on Payments page

==================================================
EXPECTED EDIT WORKFLOW
==================================================

Payments
    ↓
Actions
    ↓
Edit
    ↓
Edit Payment Modal
    ↓
Payment Status
    ↓
Save
    ↓
Backend validation
    ↓
Update Payment
    ↓
Update DataTable
    ↓
Update filtered totals if necessary
    ↓
Toast:
"Payment updated successfully."
    ↓
Remain on Payments page

==================================================
EXPECTED DOWNLOAD WORKFLOW
==================================================

Admin applies:

Search
Status
Date Range

        ↓

Payments DataTable updates

        ↓

Admin selects:

Download
    ↓
PDF / Excel / CSV

        ↓

Laravel applies SAME filters

        ↓

All matching Payment records retrieved

        ↓

Report generated

        ↓

File downloaded

==================================================
IMPORTANT REPORT EXAMPLE
==================================================

Suppose:

Database has:
500 Payment records

Admin filters:

Payment Status:
Paid

From:
October 1, 2026

To:
October 31, 2026

Search:
Covered Court

Matching:
42 Payment records

DataTable currently displays:
10 rows

PDF/Excel/CSV must contain:

ALL 42 matching records

NOT:

10

NOT:

500

==================================================
TESTS — DATATABLE
==================================================

Add/update tests for:

1. Authorized admin can access Payments.

2. Unauthorized resident cannot access Payments Management.

3. Payments DataTable loads.

4. Payment ID displays correctly.

5. Reservation ID displays correctly.

6. Resident relationship displays correctly.

7. Resource relationship displays correctly.

8. Reservation Date displays correctly.

9. Total uses Reservation.total.

10. Total is formatted correctly.

11. Payment Status displays correctly.

12. Search works.

13. Payment Status filter works.

14. From Date filter works.

15. To Date filter works.

16. Combined filters work.

17. Pagination works.

18. Rows per page works.

19. Sorting works where implemented.

20. Empty results are handled correctly.

==================================================
TESTS — ACTIONS
==================================================

21. Payment shows View.

22. Payment shows Edit.

23. Payment does NOT show Delete.

24. View opens Payment details correctly.

25. View uses Reservation.total.

26. Edit opens Payment edit correctly.

27. Reservation fields are read-only in Edit.

28. Reservation.total cannot be modified through Payment Edit.

29. Authorized admin can update Payment Status.

30. Invalid Payment Status is rejected.

31. Editing Payment Status does not change Reservation Status.

32. Payment edit updates the DataTable.

33. Payment edit stays on Payments page.

==================================================
TESTS — PAYMENT HISTORY
==================================================

34. Reservation reschedule preserves Payment.

35. Reservation cancellation preserves Payment.

36. Official Use reschedule conflict resolution preserves Payment.

37. Official Use cancellation conflict resolution preserves Payment.

38. Reservation cancellation does not automatically set Payment to
    Refunded.

39. Payment records cannot be deleted through Payments Management.

==================================================
TESTS — SUMMARY
==================================================

40. Total Payments reflects filtered dataset.

41. Total uses Reservation.total.

42. Total represents all matching records, not only current page.

43. Status filter updates summary.

44. Date filter updates summary.

45. Search updates summary.

==================================================
TESTS — PDF
==================================================

46. Authorized admin can download PDF.

47. Unauthorized resident cannot download PDF.

48. PDF contains Payment ID.

49. PDF contains Reservation ID.

50. PDF contains Resident.

51. PDF contains Resource.

52. PDF contains Reservation Date.

53. PDF uses Total.

54. PDF does not use Amount terminology.

55. PDF contains Payment Status.

56. PDF excludes Actions.

57. PDF respects Search.

58. PDF respects Status filter.

59. PDF respects From/To dates.

60. PDF exports all filtered records, not only current page.

61. PDF total uses only filtered records.

62. PDF respects barangay scoping.

==================================================
TESTS — EXCEL
==================================================

63. Authorized admin can download Excel.

64. Unauthorized resident cannot download Excel.

65. Excel contains correct columns.

66. Excel uses Total.

67. Excel respects Search.

68. Excel respects Status filter.

69. Excel respects Date filters.

70. Excel exports all filtered records.

71. Excel does not include Actions.

72. Excel respects barangay scoping.

==================================================
TESTS — CSV
==================================================

73. Authorized admin can download CSV.

74. Unauthorized resident cannot download CSV.

75. CSV contains correct columns.

76. CSV uses Total.

77. CSV respects Search.

78. CSV respects Status filter.

79. CSV respects Date filters.

80. CSV exports all filtered records.

81. CSV does not include Actions.

82. CSV respects barangay scoping.

==================================================
TESTS — EDIT + EXPORT
==================================================

83. Editing Payment Status updates future PDF exports.

84. Editing Payment Status updates future Excel exports.

85. Editing Payment Status updates future CSV exports.

86. Paid filter no longer includes Payment after it becomes Refunded.

87. Refunded filter includes updated Payment.

==================================================
REGRESSION TESTS
==================================================

88. Existing Reservation Management tests pass.

89. Existing Payment tests pass.

90. Existing Official Use tests pass if implemented.

91. Existing Calendar tests pass.

92. Existing Notification tests pass.

93. Existing authentication tests pass.

94. Existing authorization tests pass.

95. Existing barangay/SaaS isolation tests pass.

==================================================
FINAL BUSINESS RULES
==================================================

PAYMENTS DATATABLE COLUMNS:

Payment ID
Reservation ID
Resident
Resource
Reservation Date
Total
Payment Status
Actions


PAYMENT ACTIONS:

View
Edit

NO Delete.


TOTAL:

Use:

Reservation.total

Do NOT rename it to Amount.

Do NOT introduce duplicate Payment.amount merely for this report.


VIEW:

Show Payment and related Reservation information in a same-page modal.


EDIT:

Allow appropriate Payment fields such as:

Payment Status

Do NOT allow Payment Edit to change:

Resident
Resource
Reservation Date
Reservation Time
Reservation.total
Reservation Status


PAYMENT STATUS:

Changing Payment Status does NOT automatically change Reservation Status.


CANCELLATION:

Reservation Cancelled does NOT automatically mean Payment Refunded.


RESCHEDULE:

Preserve existing Payment.


OFFICIAL USE:

Official Use has no Payment.

Official Use conflict resolution must preserve Payment history.


DOWNLOAD:

Support:

PDF
Excel
CSV


EXPORTS MUST RESPECT:

Search
Payment Status
From Date
To Date
Barangay/Tenant Scope


EXPORT ALL FILTERED RECORDS:

Do NOT export only the current DataTable page.


REPORT TOTAL:

Calculate using:

Reservation.total

for all filtered Payment records.


SECURITY:

Residents cannot access admin Payment reports.

Admins cannot access another barangay's Payment records.

Preserve Super Admin behavior.


HISTORY:

Do not delete financial history.

==================================================
IMPORTANT IMPLEMENTATION RULE
==================================================

Do not modify unrelated features unnecessarily.

Do not reset or reseed the database.

Do not delete existing Payment records.

Do not create duplicate Payment records.

Do not create duplicate financial fields unnecessarily.

Do not change Reservation.total from Payments Management.

Do not rebuild Reservation Management.

Do not rebuild Official Use.

Do not rebuild Calendar.

Only integrate with those features where necessary.

==================================================
AFTER IMPLEMENTATION
==================================================

Report clearly:

1. Files created
2. Files modified
3. Existing Payment schema discovered
4. Existing Payment statuses discovered
5. Payment → Reservation relationship used
6. DataTable implementation
7. Search implementation
8. Filter implementation
9. Pagination implementation
10. Summary implementation
11. View modal implementation
12. Edit modal implementation
13. Payment status update logic
14. PDF export implementation
15. Excel export implementation
16. CSV export implementation
17. Shared filtered query implementation
18. Reservation.total usage
19. Barangay/SaaS scoping
20. Authorization/security
21. Packages reused/installed, if any
22. Tests added/modified
23. Full test results

IMPORTANT:

Do not claim functionality works unless it has actually been implemented
and verified.

If the current eReserve architecture differs from the examples in this
prompt, adapt the technical implementation to the existing architecture
while preserving ALL business rules above.