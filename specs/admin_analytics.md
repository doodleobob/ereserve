You are working inside my existing Laravel project `ereserve`.

I want to add ADMIN ANALYTICS to my existing Admin Dashboard.

IMPORTANT:
Analyze and read my existing project FIRST before making any changes.

Do not immediately start coding.

The analytics must align with my existing:

- Laravel architecture
- MySQL database
- Admin Dashboard
- Reservation model
- Facility model
- User model
- barangay scoping
- reservation statuses
- payment logic
- Hourly Rate
- Total Paid
- Booked/In Use logic
- Available/Unavailable logic
- Blade UI
- existing CSS/JavaScript
- authorization

Do NOT rebuild my dashboard.

Keep my existing dashboard summary cards and add analytics below them.

==================================================
CURRENT BUSINESS RULES
==================================================

My reservation statuses are internally:

- Pending
- Accepted
- Rejected

For users:

Accepted is displayed as:

BOOKED

My payment rule is:

Pending
→ admin reviews reservation
→ admin determines/finalizes Total Payment
→ payment is received/confirmed
→ admin accepts reservation
→ reservation becomes Accepted
→ user sees Booked
→ amount is shown as Total Paid

Therefore:

ACCEPTED = PAYMENT CONFIRMED + BOOKED

Only Accepted reservations should count as collected payment.

Do NOT count Pending or Rejected reservations as collected payment.

==================================================
1. ANALYZE EXISTING DASHBOARD FIRST
==================================================

Inspect:

- Admin Dashboard controller
- Admin Dashboard Blade view
- existing dashboard cards
- Reservation model
- Facility model
- User model
- migrations
- reservation date/time fields
- reservation status field
- barangay field/scoping
- Hourly Rate
- Total Payment / Total Paid implementation
- calendar
- existing CSS
- existing JavaScript
- existing chart libraries
- routes
- tests

Search the project for existing:

- analytics
- statistics
- dashboard counts
- charts
- total_payment
- total_paid
- hourly_rate
- accepted
- pending
- rejected
- barangay

Reuse existing functionality.

Do NOT create duplicate analytics logic if something equivalent already
exists.

==================================================
2. KEEP EXISTING DASHBOARD CARDS
==================================================

Do NOT remove my existing dashboard cards.

Keep the existing cards such as:

- Available Facilities
- Pending Requests
- Accepted Reservations
- Rejected Requests

Preserve their current design.

If their existing calculations are already correct, reuse them.

Add the new Analytics section BELOW the existing dashboard content in
a location that fits the current design.

==================================================
3. ANALYTICS DATE FILTER
==================================================

Add an analytics period filter.

Provide:

- Last 7 Days
- Last 30 Days
- This Month

If it fits the existing architecture cleanly, also provide:

- Custom Date Range

Example:

Analytics                         [ Last 30 Days ▼ ]

When the selected period changes, all analytics below should use the
same period.

Do not mix different date periods between analytics cards/charts.

Use the reservation's submission/creation date for reservation-request
trend analytics.

Use the appropriate confirmed/accepted date if the project stores one
for payment collection analytics.

If no accepted timestamp exists, analyze the existing schema and use
the safest existing timestamp without inventing inaccurate data.

==================================================
4. BARANGAY SCOPING — CRITICAL
==================================================

A barangay ADMIN must ONLY see analytics for THEIR barangay.

Example:

Washington Admin

Analytics must only include:

- Washington reservations
- Washington facilities/equipment
- Washington reservation payments
- Washington users where relevant

Do NOT include data from:

- Taft
- Mabua
- other barangays

Enforce this SERVER-SIDE.

Do not send all barangay data to JavaScript and filter it in the
browser.

Reuse my existing barangay authorization/scoping.

==================================================
5. ANALYTICS SUMMARY
==================================================

Add a small analytics summary for the selected period.

Show:

TOTAL RESERVATION REQUESTS

Number of reservations submitted during the selected period.

BOOKED RESERVATIONS

Number of Accepted reservations.

Remember:

Database = Accepted
User-facing terminology = Booked

PENDING REQUESTS

Number of Pending reservations.

REJECTED REQUESTS

Number of Rejected reservations.

TOTAL COLLECTED

Sum of the final payment amount for Accepted reservations.

Example:

Total Collected
₱15,500.00

IMPORTANT:

Only include reservations where:

status = Accepted

because in my current business rule:

Accepted = payment confirmed.

Do NOT include:

Pending
Rejected

in Total Collected.

==================================================
6. RESERVATIONS OVER TIME
==================================================

Add a chart:

RESERVATIONS OVER TIME

This should show the number of reservation requests submitted over the
selected period.

Example:

Sep 1     2
Sep 2     4
Sep 3     1
Sep 4     6
Sep 5     3

A line chart or bar chart is appropriate.

Use real database data.

Do not hardcode sample values.

==================================================
7. RESERVATION STATUS BREAKDOWN
==================================================

Add:

RESERVATION STATUS

Show:

Pending
Booked
Rejected

Remember:

For analytics display:

Accepted → Booked

Do not change the actual database status from Accepted to Booked.

Example:

Pending     5
Booked     18
Rejected    3

A doughnut/pie chart is appropriate if it matches the existing UI.

==================================================
8. MOST RESERVED FACILITIES / EQUIPMENT
==================================================

Add:

MOST RESERVED FACILITIES / EQUIPMENT

Rank facilities/equipment based on reservation REQUEST count.

Example:

1. Covered Court       24 requests
2. Chairs              18 requests
3. Tables              12 requests

This metric answers:

"What are users requesting the most?"

Include reservation requests regardless of final status unless the
existing project requirements indicate otherwise.

Clearly label it as requests so it is not confused with completed
bookings.

==================================================
9. MOST BOOKED FACILITIES / EQUIPMENT
==================================================

Also add:

MOST BOOKED FACILITIES / EQUIPMENT

This should count only:

status = Accepted

Example:

1. Covered Court       15 bookings
2. Chairs              11 bookings
3. Tables               7 bookings

This metric answers:

"Which facilities/equipment actually receive the most confirmed
bookings?"

Do not count Pending or Rejected reservations here.

==================================================
10. TOTAL COLLECTED
==================================================

Because my current eReserve business rule defines:

Accepted = Payment Confirmed

calculate:

TOTAL COLLECTED

using:

SUM(final Total Payment of Accepted reservations)

Example:

Accepted Reservation 1:
₱1,500.00

Accepted Reservation 2:
₱500.00

Accepted Reservation 3:
₱2,000.00

Total Collected:
₱4,000.00

Use the actual existing final payment field.

Do NOT create a second payment field if the project already stores the
final amount.

==================================================
11. COLLECTION TREND
==================================================

Add:

COLLECTION TREND

Show the total confirmed payment amount over time.

Example:

Sep 1     ₱1,500
Sep 2     ₱2,000
Sep 3     ₱500
Sep 4     ₱3,000

Only Accepted reservations should contribute.

Pending and Rejected reservations contribute:

₱0

Use the same selected analytics period.

==================================================
12. FACILITY UTILIZATION
==================================================

Add:

FACILITY / EQUIPMENT UTILIZATION

Keep this simple.

Use Accepted reservations to show how often facilities/equipment were
actually booked.

Example:

Covered Court
15 bookings

Chairs
11 bookings

Tables
7 bookings

If accurate duration data already exists and can be calculated safely,
you may also show:

Booked Hours

Example:

Covered Court
15 bookings
42 booked hours

But ONLY add booked-hour utilization if the existing reservation
datetime/duration logic can calculate it accurately.

Remember that eReserve supports overnight reservations such as:

10:00 PM → 1:00 AM

so utilization duration must use the same full datetime logic.

Do not introduce inaccurate time calculations.

==================================================
13. OPTIONAL RECENT ANALYTICS INSIGHT
==================================================

If it fits the current UI, show a small text summary such as:

Most Requested:
Covered Court

Most Booked:
Covered Court

Total Bookings:
18

Total Collected:
₱15,500.00

Do not generate AI-written insights.

These should simply be database-derived values.

==================================================
14. UI DESIGN
==================================================

The Analytics section must MATCH my existing eReserve Admin Dashboard.

Reuse:

- existing blue color palette
- cards
- typography
- border radius
- shadows
- spacing
- icons
- responsive layout

Do NOT redesign the entire Admin Dashboard.

Do NOT introduce another CSS framework.

A possible structure is:

--------------------------------------------------

Existing Dashboard Cards

[ Available ] [ Pending ] [ Accepted ] [ Rejected ]

Recent Reservations
Quick Actions


ADMIN ANALYTICS                     [ Last 30 Days ▼ ]

[ Total Requests ] [ Booked ] [ Total Collected ]

Reservations Over Time
[ Line/Bar Chart ]

Reservation Status
[ Doughnut Chart ]

Collection Trend
[ Line/Bar Chart ]

Most Reserved             Most Booked
Facilities                Facilities

1. Covered Court          1. Covered Court
2. Chairs                 2. Chairs
3. Tables                 3. Tables

Facility Utilization
[ Chart/List ]

--------------------------------------------------

Adapt this to the actual existing dashboard.

Do not blindly implement this exact layout if the current dashboard has
a better structure.

==================================================
15. CHART LIBRARY
==================================================

First check whether the project already uses a chart library.

If yes:

reuse it.

If not:

use a lightweight chart library compatible with the existing Laravel
Blade/JavaScript architecture.

Do NOT introduce:

- React
- Vue
- Angular

just for analytics.

Do not add an unnecessary frontend framework.

==================================================
16. EMPTY STATES
==================================================

Analytics must work when there is no data.

Examples:

No reservation data available for this period.

Total Requests:
0

Booked:
0

Total Collected:
₱0.00

Charts must not crash when datasets are empty.

==================================================
17. PERFORMANCE
==================================================

Use efficient database queries.

Prefer Laravel/database aggregates such as:

- count()
- sum()
- groupBy()

where appropriate.

Do NOT load every reservation into memory just to count them.

Avoid N+1 queries.

Reuse shared scoped queries where practical.

==================================================
18. SECURITY
==================================================

All analytics authorization must be enforced server-side.

A normal admin must never be able to manipulate:

- URL parameters
- date filters
- JavaScript
- request data

to access another barangay's analytics.

Use the existing authorization architecture.

==================================================
19. SUPER ADMIN
==================================================

Inspect the existing super_admin implementation.

Preserve its existing cross-barangay permissions.

Do not change normal admin permissions.

If the super_admin already has a barangay selection/filtering
architecture, analytics may follow it.

Do not invent a new authorization system solely for analytics.

==================================================
20. DO NOT CHANGE RESERVATION LOGIC
==================================================

Analytics should READ existing data.

Do NOT change:

- reservation submission
- Pending/Accepted/Rejected workflow
- Accepted = payment confirmed rule
- Booked user-facing label
- Hourly Rate
- Total Payment
- Total Paid
- payment confirmation
- in-app notifications
- notification bell
- overlapping reservation request behavior
- Partially Booked
- Booked
- In Use
- Available/Unavailable
- calendar
- overnight reservations

==================================================
21. DATABASE SAFETY
==================================================

Do not create unnecessary analytics tables.

Analytics should preferably calculate from existing data.

Do NOT run:

php artisan migrate:fresh
php artisan migrate:refresh
php artisan db:wipe

Do NOT modify `.env`.

Preserve my existing MySQL data.

==================================================
22. TESTING
==================================================

Test at minimum:

1. Admin analytics page/section loads.
2. Existing dashboard cards remain working.
3. Normal admin only sees own barangay analytics.
4. Another barangay's data is excluded.
5. Total Reservation Requests is correct.
6. Pending count is correct.
7. Booked count uses Accepted reservations.
8. Rejected count is correct.
9. Total Collected uses Accepted reservations only.
10. Pending payments are excluded from Total Collected.
11. Rejected payments are excluded from Total Collected.
12. Reservations Over Time uses real data.
13. Status breakdown is correct.
14. Accepted displays as Booked in analytics.
15. Most Reserved uses request count.
16. Most Booked uses Accepted count.
17. Collection Trend uses Accepted payments only.
18. Date filters work correctly.
19. Empty periods display safely.
20. Overnight reservations do not break utilization calculations.
21. Existing payment workflow remains unchanged.
22. Existing notification system remains unchanged.
23. Existing calendar remains working.
24. Booked/Partially Booked/In Use remain working.
25. Responsive dashboard works.

Do not run destructive tests against my development MySQL database.

==================================================
FINAL REPORT
==================================================

After implementation, provide:

1. Existing dashboard architecture found.
2. Existing statistics found.
3. Analytics added.
4. Barangay-scoping approach.
5. Date-filter implementation.
6. Total Requests calculation.
7. Booked calculation.
8. Total Collected calculation.
9. Reservations Over Time implementation.
10. Reservation Status implementation.
11. Most Reserved implementation.
12. Most Booked implementation.
13. Collection Trend implementation.
14. Facility Utilization implementation.
15. Chart library used.
16. Controllers/routes changed.
17. Blade/CSS/JavaScript changed.
18. Tests performed.
19. Test results.
20. Complete list of files modified.

Do not expose credentials, passwords, APP_KEY, Gmail credentials,
OTP codes, or other secrets.