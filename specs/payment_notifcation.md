You are working inside my existing Laravel project `ereserve`.

I want to implement TWO connected features:

1. PAYMENT / PRICING FEATURE
2. IN-APP NOTIFICATION SYSTEM for USER and ADMIN

IMPORTANT:
Analyze and read my existing project FIRST before making changes.

Do NOT rebuild my existing reservation system.

The implementation must align with my current:

- Laravel architecture
- MySQL database
- Reservation model
- Facility model
- User model
- controllers
- routes
- Blade views
- existing UI/CSS
- admin dashboard
- user dashboard
- barangay scoping
- role authorization
- reservation workflow
- calendar
- Booked/In Use logic
- Available/Unavailable logic

Make minimal changes and reuse existing functionality whenever possible.

==================================================
CURRENT ERESERVE BUSINESS RULES
==================================================

Roles:

- user
- admin
- super_admin

Reservation statuses:

- Pending
- Accepted
- Rejected

Multiple users may submit reservation requests for the same
facility/equipment and time.

The admin decides which reservation to accept.

Do NOT change this behavior.

The system uses multiple barangays.

A barangay admin should only manage reservations, facilities,
notifications, and pricing that they are authorized to manage under
the existing barangay-scoping rules.

==================================================
PART 1 — ANALYZE EXISTING PROJECT
==================================================

Before writing code, inspect:

- User model
- Facility model
- Reservation model
- facility migrations
- reservation migrations
- FacilityController
- ReservationController
- admin reservation controller/logic
- facility creation/editing
- reservation creation
- reservation acceptance
- reservation rejection
- Admin Dashboard
- Reservation Management
- Facility Management
- User Dashboard
- Facilities page
- My Reservations
- user/admin layouts
- top blue header
- navigation
- existing CSS
- existing JavaScript
- existing notification implementation
- existing notification table
- existing pricing/payment fields
- existing tests

Search for:

notification
notifications
Notifiable
DatabaseNotification
price
hourly_rate
rate
payment
total_payment
amount
accepted
pending
rejected
barangay

Reuse existing fields/features when possible.

Do NOT create duplicate functionality.

==================================================
PART 2 — HOURLY RATE
==================================================

Each facility/equipment should have:

HOURLY RATE

Example:

Covered Court
Hourly Rate: ₱500.00 / hour

First determine whether the Facility model/database already contains
an equivalent pricing field.

If it already exists:
reuse it.

If it does not exist:
create the minimum safe incremental migration and implementation.

Use an appropriate monetary database type such as DECIMAL.

Do NOT use destructive database commands.

==================================================
ADMIN SETS HOURLY RATE
==================================================

The authorized ADMIN should be able to set the Hourly Rate when:

- creating a facility/equipment
- editing a facility/equipment

Example:

Facility Name:
Covered Court

Hourly Rate:
₱ [ 500.00 ]

Status:
Available

[ Save ]

Validate the Hourly Rate server-side.

Requirements:

- numeric
- zero or greater if zero-priced facilities are allowed
- cannot be negative
- appropriate decimal precision

Normal users CANNOT edit the Hourly Rate.

Admins from another barangay must not be able to edit another
barangay's facility rate.

Preserve existing authorization.

==================================================
USER SEES HOURLY RATE
==================================================

Normal users should see the Hourly Rate when browsing/viewing a
facility/equipment.

Example:

----------------------------------

Covered Court

Available

Hourly Rate
₱500.00 / hour

[ Reserve ]

----------------------------------

Use Philippine Peso formatting.

Examples:

₱100.00 / hour
₱500.00 / hour
₱1,250.00 / hour

==================================================
IMPORTANT USER PRICING RULE
==================================================

BEFORE ACCEPTANCE:

The user should ONLY see the Hourly Rate.

Do NOT show:

- Calculated Amount
- Suggested Total
- Total Payment

while the reservation is Pending.

Example:

Hourly Rate:
₱500.00 / hour

Selected Time:
2:00 PM – 5:00 PM

[ Reserve ]

Do NOT display:

Total Payment: ₱1,500.00

to the user at this stage.

==================================================
PART 3 — RATE SNAPSHOT
==================================================

Protect existing reservations from future rate changes.

Example:

User submits reservation when:

Hourly Rate = ₱500.00/hour

Later the admin changes the facility to:

Hourly Rate = ₱600.00/hour

The existing reservation should retain the rate that applied when the
reservation was submitted.

If appropriate for the existing architecture, store a snapshot of the
Hourly Rate on the reservation.

Example concept:

hourly_rate_snapshot

Do NOT blindly use this exact column name.

First inspect the existing schema and choose a name consistent with
the project.

New reservations use the current facility rate.

Existing reservations preserve their original rate.

==================================================
PART 4 — ADMIN TOTAL PAYMENT
==================================================

In Reservation Management, the admin should see:

- User
- Facility/Equipment
- Date
- Start Time
- End Time
- Duration
- Hourly Rate
- Calculated Amount
- Total Payment
- Status

Example:

----------------------------------

Reservation Request

User:
Tracy

Facility:
Covered Court

Date:
September 25, 2026

Time:
2:00 PM – 5:00 PM

Duration:
3 hours

Hourly Rate:
₱500.00 / hour

Calculated Amount:
₱1,500.00

Total Payment:

₱ [ 1500.00 ]

[ Save ]

[ Accept ]
[ Reject ]

----------------------------------

==================================================
CALCULATED AMOUNT
==================================================

Calculate the suggested amount using:

Hourly Rate × Reservation Duration

Example:

₱500.00/hour × 3 hours

Calculated Amount:
₱1,500.00

Use the reservation's rate snapshot when appropriate.

The Calculated Amount is primarily for the ADMIN.

Do NOT expose this calculated total to the normal user while the
reservation is Pending.

==================================================
ADMIN CAN EDIT TOTAL PAYMENT
==================================================

The system-calculated amount is only the suggested/default amount.

The authorized admin must be able to edit the final:

TOTAL PAYMENT

Example:

Calculated Amount:
₱1,500.00

Total Payment:
₱ [ 1400.00 ]

The admin may finalize:

₱1,400.00

even if the calculated amount was:

₱1,500.00

Store the admin-confirmed Total Payment on the reservation.

==================================================
TOTAL PAYMENT VALIDATION
==================================================

Validate Total Payment server-side.

Requirements:

- numeric
- cannot be negative
- appropriate monetary precision
- authorized admin only

Normal users must NEVER be able to edit Total Payment.

Do not rely only on hiding the input field.

Protect the update server-side.

==================================================
PART 5 — ACCEPTING RESERVATION
==================================================

Preferred workflow:

User submits reservation

→ Status: Pending

→ Correct barangay admin receives in-app notification

→ Admin opens Reservation Management

→ Admin sees Hourly Rate

→ Admin sees Duration

→ System shows Calculated Amount

→ Admin confirms/edits Total Payment

→ Admin clicks Accept

→ Reservation becomes Accepted

→ Final Total Payment is stored

→ User receives an in-app notification

→ User can now see Total Payment

Do not accept the reservation with an invalid/missing Total Payment
if Total Payment is required by this workflow.

==================================================
PART 6 — USER AFTER ACCEPTANCE
==================================================

Once the reservation is Accepted, the user may see:

----------------------------------

Covered Court

Status:
Accepted

Date:
September 25, 2026

Time:
2:00 PM – 5:00 PM

Hourly Rate:
₱500.00 / hour

Total Payment:
₱1,500.00

Payment Method:
Pay at Barangay

Please proceed to your barangay and look for the assigned staff
to complete your payment.

Please bring a valid ID for verification.

----------------------------------

The Total Payment must be READ-ONLY for the user.

==================================================
PAYMENT TERMINOLOGY
==================================================

Use these terms consistently:

Hourly Rate
= facility/equipment cost per hour

Duration
= reserved time duration

Calculated Amount
= Hourly Rate × Duration

Total Payment
= final amount confirmed by admin

Payment Method
= Pay at Barangay

==================================================
ACCEPTED DOES NOT MEAN PAID
==================================================

This is important.

Accepted means:

The reservation was approved.

It does NOT mean:

The user has already paid.

Do NOT automatically mark an accepted reservation as Paid.

The user still needs to proceed physically to the barangay.

Do NOT implement:

- GCash
- Maya
- PayPal
- Stripe
- credit/debit cards
- online payment gateway

The current payment process is:

ON-SITE / PAY AT BARANGAY.

==================================================
PART 7 — IN-APP NOTIFICATION SYSTEM
==================================================

Implement IN-APP notifications.

These notifications exist INSIDE eReserve only.

DO NOT use:

- email notifications for reservations
- Gmail for reservation notifications
- SMS
- phone
- browser push notifications
- Firebase
- Pusher
- external notification services

My existing Gmail SMTP is for existing email-related functionality
such as email verification and email 2FA.

Do not modify it.

==================================================
DATABASE NOTIFICATIONS
==================================================

Prefer Laravel database notifications if appropriate for the existing
project.

First check whether:

- User already uses Notifiable
- notifications table already exists
- database notifications already exist

Reuse them if present.

Notifications should persist.

Refreshing the page should NOT delete notifications.

Support:

- unread
- read
- unread count
- mark as read

==================================================
PART 8 — ADMIN NEW RESERVATION NOTIFICATION
==================================================

When a USER successfully submits a reservation:

create an in-app notification for the appropriate ADMIN of the SAME
BARANGAY.

Example:

----------------------------------

New Reservation Request

A new reservation request has been submitted.

Facility:
Covered Court

Date:
September 25, 2026

Time:
2:00 PM – 5:00 PM

[ View Reservation ]

----------------------------------

Clicking View Reservation should take the admin to the existing
Reservation Management area.

==================================================
ADMIN BARANGAY SCOPING
==================================================

This is critical.

Example:

Reservation Barangay:
Taft

Notify:
Taft admin

DO NOT notify:

Washington admin
Mabua admin
other unrelated barangay admins

Use the EXISTING barangay authorization/scoping.

Do NOT create a second barangay permission system.

Enforce this server-side.

==================================================
PART 9 — USER ACCEPTED NOTIFICATION
==================================================

When the admin changes a reservation from:

Pending → Accepted

create an in-app notification for the reservation OWNER.

Example:

----------------------------------

Reservation Accepted

Your reservation for Covered Court has been accepted.

Date:
September 25, 2026

Time:
2:00 PM – 5:00 PM

Hourly Rate:
₱500.00 / hour

Total Payment:
₱1,500.00

Please proceed to your barangay and look for the assigned staff
to complete your payment.

Please bring a valid ID for verification.

----------------------------------

Use actual reservation information.

Do NOT hardcode:

- facility
- date
- time
- hourly rate
- total payment
- user
- barangay

==================================================
NO DUPLICATE ACCEPTED NOTIFICATIONS
==================================================

Only generate this notification on an actual status transition:

Pending → Accepted

Do NOT create another Reservation Accepted notification when:

Accepted → Accepted

or when an unrelated field is updated.

==================================================
PART 10 — ADMIN EDITS TOTAL AFTER ACCEPTANCE
==================================================

Allow the authorized admin to correct the Total Payment of an already
Accepted reservation if necessary.

Example:

Current Total Payment:
₱1,500.00

New Total Payment:
₱1,400.00

Saving the correction must NOT change:

Accepted

back to:

Pending

The reservation remains Accepted.

==================================================
TOTAL PAYMENT UPDATED NOTIFICATION
==================================================

If the admin changes the Total Payment of an already Accepted
reservation, notify the reservation owner in-app.

Example:

----------------------------------

Total Payment Updated

The total payment for your Covered Court reservation has been updated.

Previous Total:
₱1,500.00

Updated Total:
₱1,400.00

Please proceed to your barangay and look for the assigned staff
to complete your payment.

Please bring a valid ID for verification.

----------------------------------

Only create this notification if the amount actually changed.

Do NOT create it for:

₱1,500.00 → ₱1,500.00

==================================================
PART 11 — NOTIFICATION BELL
==================================================

Add a notification bell for BOTH:

- user
- admin

Analyze the existing eReserve header first.

Place the bell in the existing BLUE TOP HEADER.

Place it immediately BEFORE the Logout button.

Example:

--------------------------------------------------

eReserve          Name / Role / Barangay    Bell   Logout

--------------------------------------------------

Do NOT put the notification bell in the white navigation menu.

==================================================
BELL DESIGN
==================================================

The bell must match the existing eReserve UI.

Reuse the existing:

- icon library
- white header icon style
- colors
- spacing
- typography
- hover states
- border radius
- responsive behavior

If the project already uses an icon library, use its bell icon.

Do NOT use an emoji if a matching icon is available.

==================================================
UNREAD BADGE
==================================================

When unread notifications exist, display a small badge on the bell.

Examples:

1
2
5
10

For more than 99:

99+

If there are no unread notifications:

do not show the badge.

==================================================
NOTIFICATION DROPDOWN
==================================================

Clicking the bell should open a small dropdown/panel.

Example:

----------------------------------

Notifications

● Reservation Accepted

Your Covered Court reservation
has been accepted.

Total Payment: ₱1,500.00

5 minutes ago

----------------------------------

Support:

- unread notification appearance
- read notification appearance
- mark as read
- unread count
- mark all as read if appropriate

Clicking a reservation notification should navigate to the appropriate
reservation page.

==================================================
PART 12 — ADMIN TOAST
==================================================

When the admin receives a new reservation notification, also show a
small IN-APP toast/popup.

Example:

----------------------------------

New Reservation Request

A new reservation request has been submitted.

Covered Court
Sep 25, 2026
2:00 PM – 5:00 PM

[ View Reservation ]    [X]

----------------------------------

The toast should:

- appear inside eReserve
- be small
- be non-blocking
- match existing UI
- be dismissible
- disappear automatically after a reasonable time

Do NOT use:

alert()

Do NOT add external push-notification infrastructure.

Use the simplest implementation appropriate for the existing Laravel
Blade application.

==================================================
PART 13 — NOTIFICATION SECURITY
==================================================

Users may only access THEIR OWN notifications.

Example:

User A must never see User B's notifications.

Barangay admins may only receive/access reservation notifications they
are authorized to access.

Enforce this SERVER-SIDE.

Do not rely only on frontend filtering.

==================================================
SUPER ADMIN
==================================================

Inspect existing super_admin authorization.

Preserve the current behavior.

Do NOT automatically send every reservation notification to the
super_admin unless the existing project architecture already requires
that.

Do not change super_admin permissions unnecessarily.

==================================================
PART 14 — UI MATCHING
==================================================

All new UI must match my EXISTING eReserve design.

This includes:

- Hourly Rate
- Total Payment
- notification bell
- notification badge
- notification dropdown
- admin toast
- reservation payment information

Reuse:

- existing blue palette
- existing cards
- existing inputs
- existing buttons
- typography
- spacing
- shadows
- border radius

Do NOT introduce another CSS framework.

Do NOT redesign the entire application.

==================================================
PART 15 — RESPONSIVE DESIGN
==================================================

Ensure the new UI works on:

- desktop
- tablet
- mobile

The notification dropdown should remain inside the viewport and become
scrollable if there are many notifications.

Do not break the existing responsive navigation.

==================================================
PART 16 — DO NOT MODIFY UNRELATED FEATURES
==================================================

Do NOT unnecessarily modify:

- registration
- login
- email verification
- email 2FA
- Gmail SMTP
- Profile
- reservation overlap behavior
- calendar
- Booked/In Use
- Available/Unavailable
- facility photo slider
- barangay scoping
- admin authorization
- super_admin authorization
- PWA

==================================================
DATABASE SAFETY
==================================================

Do NOT run:

php artisan migrate:fresh
php artisan migrate:refresh
php artisan db:wipe

Preserve all existing MySQL data.

Use safe incremental migrations if schema changes are required.

Tests must not destroy or reset my development database.

==================================================
TESTING
==================================================

Test at minimum:

PRICING:

1. Admin can set Hourly Rate when creating a facility.
2. Admin can edit Hourly Rate.
3. User can see Hourly Rate.
4. User cannot edit Hourly Rate.
5. Hourly Rate is formatted correctly.
6. Existing reservation retains its rate if facility rate later changes.

PENDING RESERVATION:

7. User can submit reservation normally.
8. Pending user sees Hourly Rate.
9. Pending user does NOT see Total Payment.

ADMIN PAYMENT:

10. Admin sees Duration.
11. Admin sees Calculated Amount.
12. Admin can edit Total Payment.
13. Negative Total Payment is rejected.
14. Invalid Total Payment is rejected.
15. Different-barangay admin cannot edit it.
16. User cannot edit Total Payment.

ADMIN NOTIFICATION:

17. New reservation notifies correct barangay admin.
18. Different barangay admin does not receive notification.
19. Admin unread badge increases.
20. Admin can open notification.
21. Admin toast appears.
22. Toast can be dismissed.
23. View Reservation opens correct reservation.

ACCEPTANCE:

24. Admin can finalize Total Payment.
25. Admin can accept reservation.
26. Reservation becomes Accepted.
27. User receives Reservation Accepted notification.
28. Another user does not receive the notification.
29. Notification shows correct facility.
30. Notification shows correct date/time.
31. Notification shows correct Hourly Rate.
32. Notification shows correct Total Payment.
33. Notification includes payment instruction.
34. Notification tells user to bring a valid ID.
35. Accepted user can see Total Payment in My Reservations.
36. No duplicate Accepted notification is generated.

PAYMENT UPDATE:

37. Admin can correct Total Payment after acceptance.
38. Reservation remains Accepted.
39. User sees updated Total Payment.
40. User receives Total Payment Updated notification.
41. Previous and updated amounts are correct.
42. Same amount does not generate an update notification.

NOTIFICATIONS:

43. User can only access own notifications.
44. Admin notifications respect barangay scoping.
45. Read/unread works.
46. Unread badge works.
47. Notification persists after page refresh.

REGRESSION:

48. Calendar still works.
49. Booked/In Use still works.
50. Available/Unavailable still works.
51. Overlapping reservation request behavior remains unchanged.
52. Email verification still works.
53. Email 2FA still works.
54. Existing role authorization remains working.

==================================================
FINAL BUSINESS RULES
==================================================

USER BEFORE ACCEPTANCE:

Hourly Rate:
YES

Total Payment:
NO

Can edit Hourly Rate:
NO

Can edit Total Payment:
NO


ADMIN:

Can set Hourly Rate:
YES

Can edit Hourly Rate:
YES

Can see Calculated Amount:
YES

Can set/edit Total Payment:
YES


USER AFTER ACCEPTANCE:

Hourly Rate:
YES

Total Payment:
YES

Can edit either:
NO

Receives in-app notification:
YES

Payment instruction:
Proceed to barangay and find assigned staff.

Identification instruction:
Bring a valid ID for verification.


ADMIN WHEN NEW RESERVATION IS SUBMITTED:

Database in-app notification:
YES

Notification bell:
YES

Small in-app toast:
YES

Same-barangay only:
YES

==================================================
FINAL REPORT
==================================================

After implementation, report:

1. Existing architecture found.
2. Existing pricing/payment fields found.
3. Existing notification functionality found.
4. Database changes made.
5. Hourly Rate implementation.
6. Rate snapshot implementation.
7. Calculated Amount logic.
8. Admin Total Payment implementation.
9. User payment visibility.
10. User Accepted notification.
11. Total Payment Updated notification.
12. Admin New Reservation notification.
13. Barangay notification scoping.
14. Notification bell/dropdown.
15. Admin toast.
16. Routes changed.
17. Controllers/models changed.
18. Blade/CSS/JS changed.
19. Migrations created.
20. Tests performed and results.
21. Complete list of files modified.

Do not expose:

- passwords
- OTPs
- Gmail credentials
- APP_KEY
- database credentials
- other secrets