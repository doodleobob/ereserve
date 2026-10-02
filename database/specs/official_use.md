You are working inside my existing Laravel project:

eReserve: A Cloud-Based Public Facility Reservation and Resource
Utilization Management System.

==================================================
IMPORTANT — INSPECT THE PROJECT FIRST
==================================================

Before modifying anything:

1. Inspect the existing Laravel architecture.
2. Inspect the current Reservation Management implementation.
3. Inspect the existing Reservation DataTable.
4. Inspect Reservation models/controllers/routes.
5. Inspect Facility/Resource models and relationships.
6. Inspect the existing Calendar implementation.
7. Inspect the existing Notification system.
8. Inspect the existing Payment system.
9. Inspect the existing barangay/SaaS scoping.
10. Inspect existing migrations before creating new database fields.
11. Inspect existing modal/AJAX/fetch patterns.
12. Inspect existing tests.

Do NOT rebuild functionality that already exists.

Reuse existing:

- Laravel architecture
- Blade templates
- Controllers/services
- Models
- Routes
- JavaScript
- Modals
- Toasts
- Notifications
- Calendar
- Reservation validation
- Payment relationships
- Barangay/tenant scoping

Do NOT introduce:

- React
- Vue
- unnecessary new frameworks
- duplicate reservation logic
- duplicate notification systems
- duplicate calendar systems

==================================================
CURRENT RESERVATION MANAGEMENT RULES
==================================================

Reservation Management is already the MAIN place where admins manage
resident reservations.

Keep these action rules:

PENDING

Actions:
- View
- Accept
- Reject


ACCEPTED

Actions:
- View
- Edit


REJECTED

Actions:
- View


CANCELLED

Actions:
- View


Do NOT change this action structure.

==================================================
ACCEPTED → EDIT
==================================================

Accepted Reservation → Edit already serves as the admin management
action for an accepted booking.

Inside Edit, the admin can:

1. Reschedule Reservation
2. Cancel Reservation

This should remain the ONE reusable workflow for changing accepted
reservations.

Do NOT create a second rescheduling/cancellation implementation for
Official Use.

Official Use must reuse this existing Accepted → Edit workflow.

==================================================
NEW FEATURE
==================================================

Now implement:

OFFICIAL USE MANAGEMENT

Official Use represents a facility/equipment/resource schedule created
by the barangay/government for official purposes.

Examples:

- Barangay Assembly
- Barangay Meeting
- Government Program
- Community Activity
- Barangay Event
- Official Government Activity

Official Use is NOT a resident reservation.

==================================================
CORE ARCHITECTURE
==================================================

Keep these separate:

RESERVATION
= resident booking/request

OFFICIAL USE
= barangay/government resource schedule

Do NOT create a fake resident/user to represent Official Use.

Do NOT store Official Use as a normal resident reservation.

Official Use must have its own model/table.

However:

Reservations and Official Use use the SAME facilities/resources.

Therefore they interact through:

- availability
- scheduling
- conflict detection
- calendar

==================================================
PART 1 — OFFICIAL USE DATABASE
==================================================

Create an Official Use model/table if one does not already exist.

Use Laravel naming conventions.

Suggested table:

official_uses

Use the actual existing project relationships and field names.

Possible fields:

id

barangay_id
or the project's existing tenant ownership field

facility_id/resource_id
using the actual existing resource relationship

date

start_time

end_time

purpose

status

created_by
if appropriate with the existing admin architecture

created_at
updated_at

IMPORTANT:

Do NOT duplicate:

facility name
resource name
barangay name
admin name

if those already exist through foreign-key relationships.

==================================================
OFFICIAL USE STATUS
==================================================

Use simple statuses:

ACTIVE

CONFLICT

If the project allows Official Use itself to be cancelled, also support:

CANCELLED

Do NOT use:

Awaiting Resolution

The status I want is:

Conflict

==================================================
STATUS MEANING
==================================================

ACTIVE

means:

The Official Use schedule currently has no unresolved Accepted resident
reservation conflicts.


CONFLICT

means:

At least one Accepted resident reservation overlaps the Official Use
schedule and still needs to be resolved.


CANCELLED

means:

The Official Use itself was cancelled, if this functionality exists.

==================================================
PART 2 — OFFICIAL USE NAVIGATION
==================================================

Add:

Official Use

to the admin navigation.

Place it logically near:

Reservation Management
Facility Management
Calendar

Follow the existing navigation structure.

Only authorized admins should access Official Use Management.

Preserve Super Admin behavior according to existing project rules.

==================================================
PART 3 — OFFICIAL USE PAGE
==================================================

Create an admin page:

Official Use

Suggested subtitle:

Schedule barangay/government use of facilities and equipment.

Primary action:

+ Add Official Use

Below it:

Official Use DataTable

==================================================
PART 4 — OFFICIAL USE DATATABLE
==================================================

Use a DataTable consistent with Reservation Management.

Columns:

Official Use ID
Resource
Schedule
Purpose
Status

Example:

---------------------------------------------------------------
ID | Resource | Schedule              | Purpose        | Status
---------------------------------------------------------------

1  | Chairs   | Oct 10, 2026          | Barangay       | Active
   |          | 1:00 PM – 5:00 PM     | Meeting        |

2  | Court    | Oct 15, 2026          | Barangay       | Conflict
   |          | 8:00 AM – 12:00 PM    | Event          |

---------------------------------------------------------------

==================================================
NO OFFICIAL USE ACTIONS COLUMN
==================================================

Do NOT add:

Actions

Do NOT add:

View Details
Review Conflict
Edit Reservation
Reschedule
Cancel Reservation

There should be NO three-dot menu on Official Use rows.

The Official Use table is mainly for:

- schedule visibility
- monitoring
- status visibility

Resident reservation management belongs in Reservation Management.

==================================================
DATATABLE FEATURES
==================================================

Provide:

Search

Resource filter

Status filter

From Date

To Date

Rows per page

Sorting

Pagination

Suggested:

Show:
10
25
50
100

Keep the design consistent with Reservation Management.

==================================================
STATUS FILTER
==================================================

Use:

Status:

[ All Statuses ▼ ]

Options:

All
Active
Conflict

If Official Use cancellation exists:

Cancelled

==================================================
PART 5 — ADD OFFICIAL USE MODAL
==================================================

When admin clicks:

+ Add Official Use

open a modal.

Do NOT navigate to another page.

Use the same modal design used throughout eReserve.

Example:

------------------------------------------------
Add Official Use

Resource
[ Select Resource ▼ ]

Date
[ Date ]

Start Time
[ Time ]

End Time
[ Time ]

Purpose
[                         ]

[ Cancel ] [ Save Official Use ]
------------------------------------------------

==================================================
RESOURCE SELECTION
==================================================

Resource must come from existing facilities/equipment/resources.

Do NOT allow arbitrary resource names.

Only show resources belonging to the admin's authorized barangay/tenant.

Use the actual existing resource relationship.

==================================================
SAVE WITHOUT PAGE REDIRECT
==================================================

Use:

Modal
→ Save
→ AJAX/fetch
→ Laravel validation
→ Database transaction
→ Official Use created
→ Reservation conflicts processed
→ Notifications created
→ JSON response
→ Modal closes
→ Official Use DataTable updates
→ Toast
→ Admin remains on Official Use

Do NOT redirect to another page.

Disable Save while processing.

Prevent duplicate submissions.

==================================================
PART 6 — OFFICIAL USE VALIDATION
==================================================

Validate:

- Resource required
- Resource belongs to correct barangay
- Date required
- Start time required
- End time required
- Purpose required
- Valid schedule
- Existing Official Use overlap

Reuse existing eReserve time validation.

If eReserve already supports across-midnight schedules, preserve that
behavior.

==================================================
OFFICIAL USE VS OFFICIAL USE
==================================================

Do NOT allow invalid overlapping Official Use schedules for the SAME
resource.

Example:

Existing:

Court
Oct 10
1:00 PM – 5:00 PM

New:

Court
Oct 10
3:00 PM – 6:00 PM

Reject because they overlap.

Use proper overlap logic.

Conceptually:

existing.start_time < new.end_time

AND

existing.end_time > new.start_time

Adjacent schedules may remain allowed according to existing eReserve
rules.

Example:

1:00 PM – 5:00 PM

then:

5:00 PM – 7:00 PM

may be valid if current rules permit adjacency.

==================================================
PART 7 — AFTER OFFICIAL USE IS CREATED
==================================================

After successfully creating Official Use:

Check ALL resident reservations that overlap the Official Use.

Match:

- same barangay
- same resource
- same relevant date
- actual time overlap

Do NOT check unrelated barangays.

Do NOT check unrelated resources.

Do NOT stop after the first conflict.

Process EVERY affected reservation.

==================================================
PART 8 — PENDING RESERVATION CONFLICT
==================================================

If Official Use overlaps a:

Pending

resident reservation:

Automatically cancel the Pending reservation.

Change:

Pending
→ Cancelled

Store cancellation reason:

Official Use

or use the project's existing cancellation reason architecture.

Do NOT delete the reservation.

Preserve it in reservation history.

==================================================
PENDING USERS DO NOT CHOOSE
==================================================

Pending users do NOT choose:

Reschedule

or

Cancellation

because their booking has not yet been accepted.

Their Pending request is automatically cancelled.

They can create another reservation for another available schedule.

==================================================
NOTIFY EVERY AFFECTED PENDING USER
==================================================

Every affected Pending reservation owner must receive a notification.

Example:

Title:

Reservation Cancelled Due to Official Use

Message:

Your pending reservation for [Resource] on [Date] from [Start Time] to
[End Time] has been cancelled because the resource is required for
official barangay use.

You may create another reservation for a different available schedule.

IMPORTANT:

If five Pending reservations overlap:

notify all five users.

Do NOT stop after the first reservation.

==================================================
PENDING CONFLICT DOES NOT CREATE CONFLICT STATUS
==================================================

Pending reservations are automatically resolved through cancellation.

Therefore:

Pending conflicts alone do NOT make Official Use:

Conflict

Example:

Official Use overlaps:

Pending #10
Pending #11
Pending #12

System:

#10 → Cancelled
#11 → Cancelled
#12 → Cancelled

No Accepted conflicts exist.

Official Use:

Active

==================================================
PART 9 — ACCEPTED RESERVATION CONFLICT
==================================================

If Official Use overlaps:

Accepted

resident reservation:

DO NOT automatically cancel it.

Keep:

Reservation Status = Accepted

The Accepted booking may already have:

- admin approval
- payment
- confirmed schedule

Therefore the resident must be informed.

==================================================
OFFICIAL USE STATUS BECOMES CONFLICT
==================================================

If at least ONE Accepted reservation overlaps:

Official Use Status:

Conflict

Example:

Official Use:

Court
Oct 10
1:00 PM – 5:00 PM

Accepted Reservation:

Court
Oct 10
2:00 PM – 4:00 PM

Result:

Official Use:

Status = Conflict

Reservation:

Status = Accepted

==================================================
IMPORTANT STATUS SEPARATION
==================================================

Do NOT change:

Reservation Status = Accepted

into:

Reservation Status = Conflict

Keep the concepts separate.

Correct:

Reservation Status:
Accepted

Conflict:
Official Use


Official Use Status:
Conflict

==================================================
PART 10 — ACCEPTED USER NOTIFICATION
==================================================

Notify EVERY affected Accepted user.

Example:

Title:

Official Use Schedule Conflict

Message:

Your accepted reservation for [Resource] on [Date] from [Start Time] to
[End Time] is affected because the resource is required for official
barangay use.

Please choose whether you prefer to:

- Reschedule your reservation
- Cancel your reservation

The barangay administrator will process your selected option.

==================================================
PART 11 — USER DECISION
==================================================

The affected Accepted user should be able to choose:

RESCHEDULE

OR

CANCELLATION

IMPORTANT:

The user does NOT directly edit their Accepted reservation.

The user only records/communicates their preferred action.

The admin performs the actual change.

==================================================
CONFLICT RESOLUTION STATE
==================================================

Track the Accepted Official Use conflict separately.

Possible resolution states:

Awaiting User Decision

Reschedule Requested

Cancellation Requested

Resolved

These are NOT Reservation statuses.

Example:

Reservation Status:
Accepted

Conflict:
Official Use

Resolution:
Reschedule Requested

==================================================
PART 12 — RESERVATION MANAGEMENT INTEGRATION
==================================================

Reservation Management remains the ONLY main place where the admin
changes resident reservations.

Do NOT add reservation-management actions to Official Use.

Add a Reservation Management filter:

Conflict

Options:

All
Official Use Conflict
No Conflict

==================================================
ACCEPTED OFFICIAL USE CONFLICT DISPLAY
==================================================

An affected Accepted reservation should display enough information for
the admin to understand the situation.

Example:

Status:
Accepted

Conflict:
Official Use

Resolution:
Awaiting User Decision

or:

Resolution:
Reschedule Requested

or:

Resolution:
Cancellation Requested

==================================================
RESERVATION ACTIONS REMAIN UNCHANGED
==================================================

PENDING:

View
Accept
Reject


ACCEPTED:

View
Edit


REJECTED:

View


CANCELLED:

View

Do NOT add:

Resolve Conflict

Review Conflict

Official Use Action

to Reservation Management.

Reuse:

Accepted → Edit

==================================================
PART 13 — USER CHOOSES RESCHEDULE
==================================================

If the Accepted user chooses:

Reschedule

record:

Resolution:
Reschedule Requested

The admin then uses:

Reservation Management
→ Conflict: Official Use Conflict
→ Accepted Reservation
→ Edit
→ Reschedule Reservation

Reuse the existing Accepted Reservation Edit modal.

==================================================
RESCHEDULE
==================================================

Admin can change:

Reservation Date
Start Time
End Time

Run the existing schedule/availability validation.

The new schedule must NOT conflict with:

- another invalid Accepted booking
- Official Use
- existing resource availability rules

Exclude the reservation being edited from its own conflict check.

==================================================
AFTER SUCCESSFUL RESCHEDULE
==================================================

Keep:

Reservation Status = Accepted

Do NOT change it back to Pending.

Set the Official Use conflict record/state to:

Resolved

Preserve the existing Payment.

Do NOT create another Payment.

Recalculate related Official Use status.

==================================================
PART 14 — USER CHOOSES CANCELLATION
==================================================

If the Accepted user chooses:

Cancellation

record:

Resolution:
Cancellation Requested

Admin:

Reservation Management
→ Accepted
→ Edit
→ Cancel Reservation

Reuse the existing cancellation functionality.

==================================================
AFTER SUCCESSFUL CANCELLATION
==================================================

Change:

Accepted
→ Cancelled

Cancellation reason:

Official Use

Conflict:

Resolved

Preserve:

- Reservation history
- Payment history

Do NOT delete the Reservation.

Do NOT delete the Payment.

==================================================
REFUND RULE
==================================================

Cancellation does NOT automatically mean a refund has occurred.

Do NOT automatically change:

Paid
→ Refunded

unless an actual refund process occurs.

Reservation cancellation and Payment refund are separate events.

==================================================
PART 15 — OFFICIAL USE STATUS RECALCULATION
==================================================

After resolving any Accepted conflict, automatically recalculate the
Official Use status.

Conceptually:

if official_use itself is cancelled:

    status = cancelled

else if unresolved accepted conflicts > 0:

    status = conflict

else:

    status = active

Do NOT require the admin to manually change Official Use status.

==================================================
MULTIPLE ACCEPTED CONFLICTS
==================================================

Example:

Official Use #5

Accepted conflicts:

Reservation #20
Reservation #21
Reservation #22

Initial:

Official Use:
Conflict


Resolve #20.

Remaining:
#21
#22

Official Use:
Conflict


Resolve #21.

Remaining:
#22

Official Use:
Conflict


Resolve #22.

Remaining:
0

Official Use:
Active

IMPORTANT:

Do NOT change Conflict → Active until ALL Accepted conflicts are resolved.

==================================================
PART 16 — OFFICIAL USE CALENDAR INTEGRATION
==================================================

Official Use MUST appear in the existing eReserve Calendar.

Do NOT create a completely separate calendar.

The existing Calendar should display:

1. Accepted Resident Reservations
2. Official Use schedules

==================================================
CALENDAR ARCHITECTURE
==================================================

Keep database concepts separate:

Calendar Events
        |
        ├── Accepted Reservations
        |
        └── Official Uses

Do NOT create fake Reservation records for Official Use just to make them
appear in the calendar.

Instead, extend the existing calendar event source/controller/service to
return both event types.

==================================================
CALENDAR EVENT TYPE
==================================================

If useful for the existing frontend architecture, return something like:

event_type = reservation

or:

event_type = official_use

This may be a frontend/event property.

Do NOT add unnecessary database columns purely for calendar rendering.

==================================================
ACTIVE OFFICIAL USE IN CALENDAR
==================================================

If Official Use is:

Active

show it in the calendar.

Example:

Official Use
Barangay Assembly
1:00 PM – 5:00 PM

==================================================
CONFLICT OFFICIAL USE IN CALENDAR
==================================================

IMPORTANT:

If Official Use is:

Conflict

it MUST STILL appear in the calendar.

Do NOT hide it.

Conflict means the Official Use exists but an Accepted reservation still
overlaps it.

Example:

Official Use:

Court
Oct 10
1:00 PM – 5:00 PM
Conflict

Accepted Reservation:

Court
Oct 10
2:00 PM – 4:00 PM
Accepted

During this unresolved situation, the Calendar may temporarily show BOTH
events.

That is correct.

It represents the real unresolved conflict.

==================================================
CALENDAR CONFLICT LABEL
==================================================

Make the Official Use conflict understandable.

Example event:

Official Use
Barangay Assembly
1:00 PM – 5:00 PM

Status:
Conflict

Do not communicate Conflict only through color.

Include a textual indicator/badge where appropriate.

==================================================
CALENDAR EVENT INFORMATION
==================================================

Admin Calendar Official Use event may display:

Resource
Date
Start Time
End Time
Purpose
Status

Example:

Official Use

Resource:
Covered Court

Purpose:
Barangay Assembly

Schedule:
Oct 10, 2026
1:00 PM – 5:00 PM

Status:
Active

==================================================
CALENDAR MODAL
==================================================

If clicking calendar events already opens a modal, reuse that pattern.

Clicking Official Use may open an informational modal.

Do NOT navigate to another page.

Example:

Official Use

Resource:
Covered Court

Schedule:
Oct 10, 2026
1:00 PM – 5:00 PM

Purpose:
Barangay Assembly

Status:
Conflict

[ Close ]

Do NOT add:

Edit Reservation
Review Conflict
Resolve Conflict

inside the Calendar modal.

Reservation management belongs in Reservation Management.

==================================================
PART 17 — CALENDAR AFTER RESCHEDULE
==================================================

Example:

Official Use:

Court
Oct 10
1:00 PM – 5:00 PM
Conflict

Accepted Reservation:

Court
Oct 10
2:00 PM – 4:00 PM

User chooses:

Reschedule

Admin changes reservation to:

Oct 11
2:00 PM – 4:00 PM

After successful reschedule:

Calendar:

Oct 10
Official Use
1:00 PM – 5:00 PM

Oct 11
Accepted Reservation
2:00 PM – 4:00 PM

If no other Accepted conflicts remain:

Official Use:

Conflict → Active

==================================================
PART 18 — CALENDAR AFTER CANCELLATION
==================================================

If Accepted user chooses Cancellation:

Admin:

Reservation Management
→ Accepted
→ Edit
→ Cancel Reservation

After cancellation:

The cancelled reservation should no longer behave as an active Calendar
booking according to the existing calendar rules.

Official Use remains visible.

If no other Accepted conflicts remain:

Conflict → Active

==================================================
PART 19 — PENDING CALENDAR BEHAVIOR
==================================================

If Official Use overlaps a Pending reservation:

Pending
→ Automatically Cancelled

The cancelled Pending reservation must not behave as an active Calendar
booking.

Official Use remains visible.

==================================================
PART 20 — OFFICIAL USE BLOCKS NEW RESERVATIONS
==================================================

This is important.

Once an Official Use schedule exists, residents should NOT be able to
create a NEW reservation that overlaps it.

Example:

Official Use:

Court
Oct 10
1:00 PM – 5:00 PM

Resident attempts:

Court
Oct 10
2:00 PM – 4:00 PM

Result:

Do NOT create the new reservation.

Show an availability error such as:

"This resource is unavailable for the selected date and time."

==================================================
EXISTING VS NEW RESERVATION DISTINCTION
==================================================

CASE 1:

Accepted reservation already existed BEFORE Official Use was created.

Official Use overlaps it.

Result:

Accepted reservation remains Accepted.

Official Use = Conflict.

User chooses Reschedule or Cancellation.


CASE 2:

Official Use already exists.

Resident attempts to create a NEW overlapping reservation.

Result:

Block the new reservation.

Do NOT intentionally create another Official Use conflict.

==================================================
PART 21 — USER CALENDAR
==================================================

If residents can see the resource/facility Calendar, Official Use should
also appear there.

This lets residents understand why the resource is unavailable.

However:

Do NOT expose private/internal information.

Resident-facing Official Use event may simply show:

Official Use

Resource unavailable

Time:
1:00 PM – 5:00 PM

Do NOT expose:

- other resident names
- other users' reservation IDs
- internal conflict notes
- admin-only information
- another resident's contact information

==================================================
PART 22 — ADMIN CALENDAR
==================================================

Admin Calendar may show more information.

Example:

Official Use
Barangay Assembly

Resource:
Covered Court

Schedule:
Oct 10
1:00 PM – 5:00 PM

Status:
Conflict

But actual reservation editing still happens in:

Reservation Management

==================================================
PART 23 — PAYMENTS
==================================================

Official Use itself has:

NO Payment.

Do NOT create a Payment for Official Use.

Pending reservation automatically cancelled:

No Payment should exist under the current workflow.

Accepted reservation rescheduled:

Preserve existing Payment.

Do NOT create duplicate Payment.

Accepted reservation cancelled:

Preserve Payment history.

Do NOT automatically refund.

==================================================
PART 24 — NOTIFICATION DUPLICATES
==================================================

Prevent duplicate notifications.

Reloading:

Official Use
Reservation Management
Calendar

must NOT resend the same Official Use conflict notification.

Status recalculation must NOT create duplicate notifications.

Notifications should be generated when the relevant conflict/event
actually occurs.

==================================================
PART 25 — DATABASE TRANSACTIONS
==================================================

Creating Official Use may affect multiple records.

Use a database transaction where appropriate.

Conceptually:

BEGIN TRANSACTION

Create Official Use

Find overlapping reservations

Cancel all overlapping Pending reservations

Create/record all Accepted conflicts

Calculate Official Use status

COMMIT

Then send/dispatch notifications according to the existing notification
architecture.

Avoid partial inconsistent state.

==================================================
PART 26 — BARANGAY / SAAS SCOPING
==================================================

This is critical.

Official Use belongs to a barangay/tenant.

Only affect reservations matching:

same barangay
same resource
same schedule overlap

Example:

Barangay A Official Use

must NOT affect:

Barangay B reservations.

Barangay A Official Use must NOT appear on Barangay B's calendar.

Barangay A admin must NOT select Barangay B's resources.

Preserve existing Super Admin rules.

==================================================
PART 27 — RESPONSIVE DESIGN
==================================================

Official Use DataTable and modal must work on:

Desktop
Tablet
Mobile/PWA

Use horizontal scrolling if necessary.

Do not squeeze table columns until they become unreadable.

Modals should fit within the viewport and allow internal scrolling when
needed.

==================================================
PART 28 — MODAL DESIGN CONSISTENCY
==================================================

Reuse the same modal design as Reservation Management.

Keep consistent:

- header
- close button
- spacing
- typography
- input styling
- footer
- buttons
- backdrop
- responsive behavior

Do not create a visually unrelated modal system.

==================================================
PART 29 — DO NOT IMPLEMENT EXPORT YET
==================================================

Structure Official Use DataTable cleanly so reporting/export can be added
later.

Possible future features:

PDF
CSV
Excel
Print

BUT:

Do NOT implement export in this task.

Do NOT install export libraries yet.

==================================================
PART 30 — DO NOT BREAK EXISTING FEATURES
==================================================

Preserve:

Reservation Management DataTable

Pending:
View
Accept
Reject

Accepted:
View
Edit

Accepted Edit:
Reschedule
Cancel

Payments

Notifications

Calendar

Facility Management

Analytics

User reservation workflow

Authentication

Authorization

Barangay/SaaS scoping

Super Admin behavior

==================================================
PART 31 — TESTS
==================================================

Add/update automated tests.

Test Official Use access:

1. Authorized admin can access Official Use.

2. Unauthorized resident cannot access Official Use Management.

3. Barangay scoping is enforced.


Test DataTable:

4. Official Use DataTable loads.

5. Search works.

6. Resource filter works.

7. Status filter works.

8. Date filters work.

9. Sorting works where implemented.

10. Pagination works.

11. Rows per page works.


Test creation:

12. + Add Official Use uses modal behavior.

13. Official Use can be created.

14. Official Use belongs to correct barangay.

15. Admin cannot use another barangay's resource.

16. Invalid schedule is rejected.

17. Overlapping Official Use for same resource is rejected.

18. Adjacent schedule follows existing overlap rules.


Test Pending conflicts:

19. ALL overlapping Pending reservations are found.

20. ALL overlapping Pending reservations are automatically cancelled.

21. Reservation history is preserved.

22. ALL affected Pending users are notified.

23. Pending conflicts alone do not make Official Use Conflict.


Test Accepted conflicts:

24. ALL overlapping Accepted reservations are found.

25. Accepted reservations remain Accepted.

26. ALL affected Accepted users are notified.

27. Accepted conflict is stored separately from Reservation status.

28. Official Use becomes Conflict.

29. User can choose Reschedule.

30. User can choose Cancellation.

31. User decision alone does not directly modify the Accepted reservation.


Test Reservation Management:

32. Official Use Conflict filter works.

33. Affected reservation still shows Status = Accepted.

34. Accepted action remains View/Edit.

35. Reschedule reuses existing Accepted Edit functionality.

36. Cancellation reuses existing Accepted Edit functionality.


Test reschedule:

37. Admin can reschedule affected Accepted reservation.

38. New schedule is validated.

39. Current reservation is excluded from its own conflict check.

40. Reservation remains Accepted.

41. Conflict becomes Resolved.

42. Existing Payment is preserved.

43. Duplicate Payment is not created.


Test cancellation:

44. Admin can cancel affected Accepted reservation.

45. Accepted becomes Cancelled.

46. Cancellation reason can be Official Use.

47. Conflict becomes Resolved.

48. Reservation history remains.

49. Payment history remains.

50. Payment is not automatically Refunded.


Test Official Use status:

51. One unresolved Accepted conflict keeps Official Use = Conflict.

52. Multiple unresolved conflicts keep Official Use = Conflict.

53. Resolving only one of several conflicts does NOT change to Active.

54. Resolving final Accepted conflict changes Official Use = Active.


Test Calendar:

55. Active Official Use appears in Calendar.

56. Conflict Official Use appears in Calendar.

57. Official Use event has correct resource.

58. Official Use event has correct date.

59. Official Use event has correct start/end time.

60. Official Use event displays purpose appropriately.

61. Official Use event has correct event type.

62. Accepted reservations continue appearing normally.

63. Conflict Official Use and Accepted reservation can temporarily appear
    simultaneously.

64. Rescheduling Accepted reservation updates Calendar.

65. Cancelling Accepted reservation removes it from active booking
    behavior.

66. Official Use remains visible after conflict resolution.

67. New resident reservation cannot overlap existing Official Use.

68. Resident Calendar does not expose private information.

69. Official Use respects barangay Calendar scoping.

70. Calendar reload does not duplicate Official Use events.


Test notifications:

71. Pending users receive appropriate cancellation notification.

72. Accepted users receive appropriate conflict notification.

73. Duplicate notifications are prevented.


Regression:

74. Existing Reservation tests pass.

75. Existing Payment tests pass.

76. Existing Calendar tests pass.

77. Existing Notification tests pass.

78. Existing authentication/authorization tests pass.

==================================================
EXPECTED OFFICIAL USE PAGE
==================================================

OFFICIAL USE

Schedule barangay/government use of facilities and equipment.

[ + Add Official Use ]


Search:
[________________________]

Resource:
[ All Resources ▼ ]

Status:
[ All Statuses ▼ ]

From:
[ Date ]

To:
[ Date ]

Show:
[ 10 ▼ ] entries


----------------------------------------------------------------
ID | Resource | Schedule             | Purpose          | Status
----------------------------------------------------------------

1  | Chairs   | Oct 10, 2026         | Barangay Meeting | Active
   |          | 1:00 PM – 5:00 PM    |                  |

2  | Court    | Oct 15, 2026         | Barangay Event   | Conflict
   |          | 8:00 AM – 12:00 PM   |                  |

----------------------------------------------------------------


NO:

Actions column

View Details

Review Conflict

three-dot menu

==================================================
EXPECTED COMPLETE WORKFLOW
==================================================

ADMIN

Official Use
        ↓
+ Add Official Use
        ↓
Modal
        ↓
Select Resource
Date
Start Time
End Time
Purpose
        ↓
Save
        ↓
Validate
        ↓
Create Official Use
        ↓
Check ALL overlapping reservations


==================================================

IF PENDING:

Pending Reservation
        ↓
Automatically Cancel
        ↓
Reason = Official Use
        ↓
Notify affected user

Do this for EVERY affected Pending reservation.

Pending conflicts do NOT cause:

Official Use = Conflict


==================================================

IF ACCEPTED:

Accepted Reservation
        ↓
Keep Accepted
        ↓
Create/record Official Use conflict
        ↓
Notify affected user
        ↓
Official Use Status = Conflict
        ↓
User chooses:

Reschedule

OR

Cancellation


==================================================

IF RESCHEDULE:

User:
Reschedule Requested

        ↓

Admin:

Reservation Management
        ↓
Conflict Filter:
Official Use Conflict
        ↓
Accepted Reservation
        ↓
Edit
        ↓
Reschedule Reservation
        ↓
New Date/Time
        ↓
Validate
        ↓
Save
        ↓
Reservation remains Accepted
        ↓
Existing Payment preserved
        ↓
Conflict Resolved


==================================================

IF CANCELLATION:

User:
Cancellation Requested

        ↓

Admin:

Reservation Management
        ↓
Accepted Reservation
        ↓
Edit
        ↓
Cancel Reservation
        ↓
Confirm
        ↓
Accepted → Cancelled
        ↓
Reason = Official Use
        ↓
Payment history preserved
        ↓
Conflict Resolved


==================================================

AFTER EACH RESOLUTION:

Recalculate Official Use

If unresolved Accepted conflicts > 0:

CONFLICT

If unresolved Accepted conflicts = 0:

ACTIVE


==================================================
EXPECTED CALENDAR
==================================================

The existing Calendar displays:

ACCEPTED RESERVATIONS

and

OFFICIAL USE


Example:

OCTOBER 10

8:00 AM – 10:00 AM
Accepted Reservation

1:00 PM – 5:00 PM
Official Use
Barangay Assembly


If unresolved conflict exists:

1:00 PM – 5:00 PM
Official Use
Barangay Assembly
Conflict

AND temporarily:

2:00 PM – 4:00 PM
Accepted Reservation

After admin reschedules/cancels the Accepted reservation:

Calendar updates.

Official Use remains.

If final conflict was resolved:

Conflict → Active

==================================================
FINAL BUSINESS RULES
==================================================

1. Official Use is separate from resident Reservations.

2. Official Use has its own database model/table.

3. Official Use uses existing facilities/resources.

4. Official Use page uses a DataTable.

5. Official Use page has + Add Official Use.

6. Add Official Use uses a modal.

7. Do not navigate to another page unnecessarily.

8. Official Use table has no Actions column.

9. No View Details.

10. No Review Conflict.

11. Pending overlapping reservations are automatically cancelled.

12. Every affected Pending user is notified.

13. Pending conflicts do not cause Official Use Conflict status.

14. Accepted overlapping reservations remain Accepted.

15. Every affected Accepted user is notified.

16. Accepted conflicts cause:

Official Use Status = Conflict.

17. Reservation itself remains:

Status = Accepted.

18. User chooses:

Reschedule
or
Cancellation.

19. User does not directly modify Accepted reservation.

20. Admin handles it through:

Reservation Management → Accepted → Edit.

21. Reschedule reuses existing Edit functionality.

22. Cancellation reuses existing Edit functionality.

23. Do not create duplicate reschedule/cancellation systems.

24. Successful reschedule keeps reservation Accepted.

25. Successful reschedule preserves Payment.

26. Successful cancellation changes reservation to Cancelled.

27. Successful cancellation preserves Payment history.

28. Cancellation does not automatically mean Refunded.

29. Official Use remains Conflict until ALL Accepted conflicts are
resolved.

30. After final conflict is resolved:

Official Use → Active.

31. Active Official Use appears in Calendar.

32. Conflict Official Use ALSO appears in Calendar.

33. Existing conflicting Accepted reservation may temporarily appear
alongside Official Use in Calendar.

34. Official Use blocks NEW overlapping resident reservations.

35. Official Use has no Payment.

36. Preserve barangay/SaaS isolation.

37. Prevent duplicate notifications.

38. Preserve historical reservation/payment records.

39. Reuse existing Calendar rather than creating a separate calendar.

40. Do not reset, reseed, or delete existing production/development data
unless explicitly required by a migration.

==================================================
IMPLEMENTATION QUALITY
==================================================

Keep the implementation maintainable.

Avoid:

- duplicated conflict queries
- duplicated schedule validation
- duplicated rescheduling logic
- duplicated cancellation logic
- duplicated notification systems
- N+1 queries
- hard-coded barangay IDs
- hard-coded resource IDs
- business logic scattered unnecessarily across Blade templates

Prefer reusable application/service methods where appropriate for:

- overlap checking
- Official Use conflict processing
- Official Use status recalculation
- availability validation

Follow the project's existing architecture rather than forcing a new
pattern.

==================================================
AFTER IMPLEMENTATION
==================================================

Report:

1. Files created
2. Files modified
3. Migration/table structure
4. OfficialUse model relationships
5. Official Use DataTable implementation
6. Add Official Use modal implementation
7. Official Use validation
8. Official Use overlap validation
9. Pending reservation conflict handling
10. Accepted reservation conflict handling
11. User decision implementation
12. Reservation Management integration
13. Conflict filter implementation
14. Official Use status calculation
15. Accepted reschedule integration
16. Accepted cancellation integration
17. Payment compatibility
18. Notification implementation
19. Calendar integration
20. New-reservation availability integration
21. Barangay/SaaS scoping
22. Tests created/updated
23. Full test results

IMPORTANT:

Do not claim something is implemented unless it actually exists and has
been verified.

If an existing project structure requires a slightly different
implementation, preserve the business rules above while adapting the
technical implementation to the current Laravel architecture.