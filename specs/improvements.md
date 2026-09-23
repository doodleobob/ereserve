You are working inside my existing Laravel project (`ereserve`).

IMPORTANT:

Before changing ANY code, thoroughly inspect and understand the existing project.

Do NOT assume:
- database column names
- facility status values
- image field names
- controller names
- route names
- model relationships
- reservation status values
- barangay implementation
- calendar implementation
- CSS classes
- JavaScript structure

Read the existing implementation first and make the solution align with how this project is already built.

==================================================
PHASE 1 — ANALYZE THE EXISTING PROJECT
==================================================

Before implementing anything, inspect all files relevant to:

1. Facility creation
2. Facility editing
3. Facility images
4. Facility availability/status
5. User facility display
6. Reservation creation
7. Calendar rendering
8. Calendar status/color calculation
9. Barangay scoping
10. Admin authorization

Search the project for the actual implementation.

At minimum, inspect relevant:

- Models
- Controllers
- Migrations
- Routes
- Blade views
- JavaScript
- CSS
- Middleware
- Policies, if used
- Form requests, if used
- Support/helper classes
- Existing storage/image logic

Trace the complete flow:

ADMIN
Create/Edit Facility
        ↓
Controller
        ↓
Facility Model
        ↓
Database

and:

DATABASE
Facility
        ↓
User facility page
        ↓
Calendar
        ↓
Date/time availability display

Also trace:

Reservation
    ↓
Pending / Accepted / Rejected
    ↓
Calendar booking calculation
    ↓
Available / Partially Booked / Fully Booked
    ↓
Booked / In Use

Do not start modifying code until you understand these flows.

==================================================
PHASE 2 — FACILITY PHOTO UPLOAD
==================================================

After analyzing the existing implementation, change facility creation so the admin can upload an actual facility photo from their device.

I do NOT want a manually typed facility image URL/path field if that is what currently exists.

The admin should have something like:

Facility Photo
[ Choose File ]

Requirements:

- Use a proper file input.
- Accept appropriate image formats.
- Validate the upload server-side.
- Use Laravel's existing storage conventions.
- Store a portable relative path, not a Windows/local machine path.
- Display the uploaded image to users.
- Display it in appropriate admin facility views.
- Preserve a fallback/default image when no uploaded image exists.

IMPORTANT:

First determine whether the project already has an image/photo column.

If an appropriate column already exists, reuse it.

Do NOT create a duplicate database column unnecessarily.

If a migration is genuinely necessary, create the minimum migration required.

==================================================
PHASE 3 — FACILITY PHOTO EDITING
==================================================

When an admin edits a facility:

- Show the existing photo.
- Allow a new photo to be uploaded.
- If no new photo is uploaded, preserve the existing photo.
- If a new photo is uploaded, replace the old uploaded photo correctly.
- Do not accidentally delete a shared/default placeholder.

Follow the project's existing architecture.

==================================================
PHASE 4 — FIX FACILITY UNAVAILABLE STATUS
==================================================

There is currently an incorrect behavior:

When an admin changes a facility to Unavailable, the USER side can still show GREEN availability.

Fix this.

First inspect how the project currently stores facility availability.

Do NOT assume the field is:

status
is_available
availability

Find the actual implementation.

When the facility is manually marked unavailable by its admin, the user-facing UI must clearly display:

RED — Unavailable

It must NOT display:

GREEN — Available

==================================================
PHASE 5 — FACILITY STATUS MUST OVERRIDE CALENDAR
==================================================

The facility's admin-controlled availability must have priority over the normal reservation/calendar calculation.

Conceptually:

IF the facility itself is administratively unavailable:

    show RED "Unavailable"

ELSE:

    calculate normal reservation/calendar statuses

Do not copy this pseudocode blindly.

Implement it using the project's actual models, fields, helpers, controllers, Blade code, and JavaScript.

==================================================
PHASE 6 — KEEP EXISTING CALENDAR LOGIC
==================================================

For a facility that is enabled/available, preserve our existing calendar terminology.

LEFT-SIDE MONTH CALENDAR:

GREEN
Available

YELLOW
Partially Booked

RED
Fully Booked

The left calendar describes the status of the WHOLE DATE.

RIGHT-SIDE TIME PERIODS:

GREEN
Available

YELLOW
Booked

RED
In Use

The right side describes specific reservation/time periods.

"Booked" means an accepted reservation exists for that time.

"In Use" means the accepted reservation's actual date/time is currently happening.

Do not show "In Use" merely because a reservation was accepted.

==================================================
PHASE 7 — ADMIN UNAVAILABLE VS BOOKED
==================================================

These concepts must remain separate.

ADMIN-MARKED UNAVAILABLE:

The facility itself has been disabled/unavailable by the admin.

Display:

RED — Unavailable

The user should not be able to create a new reservation for the facility while it remains administratively unavailable.

BOOKED:

An accepted reservation exists for a specific time.

Display:

YELLOW — Booked

This must NOT prevent another user from submitting an overlapping reservation request.

IN USE:

An accepted reservation is currently happening.

Display:

RED — In Use

This also should NOT automatically prevent overlapping requests unless another existing project rule specifically requires it.

The admin still decides which overlapping reservation requests to accept/reject.

==================================================
PHASE 8 — SERVER-SIDE VALIDATION
==================================================

Do not implement these rules only visually.

If an admin-disabled facility cannot be reserved, enforce that rule in the server-side reservation creation flow.

A user must not be able to bypass the UI and manually POST a reservation for an administratively unavailable facility.

However, DO NOT introduce a server-side overlap restriction.

Our intended behavior is:

User A can request:
Facility X
6:00 PM–7:00 PM

Admin accepts User A.

User B may still request:
Facility X
6:00 PM–7:00 PM

The admin decides what to do with User B's request.

Preserve the project's existing duplicate-request protection for the SAME user if it already exists.

==================================================
PHASE 9 — BARANGAY SCOPING
==================================================

Do not break the existing barangay logic.

Inspect how barangay scoping is actually implemented.

Preserve the rule that:

- admins manage facilities for their barangay
- users see facilities for their barangay
- shared accepted reservation information is visible only within the appropriate barangay

Do not implement a new barangay architecture if the project already has one.

Reuse the existing implementation.

==================================================
PHASE 10 — COLORS
==================================================

Inspect the project's existing CSS first.

Reuse existing design variables/classes where possible.

Do not unnecessarily redesign the UI.

The intended visual meaning is:

GREEN:
Available

YELLOW/AMBER:
Partially Booked
Booked

RED:
Fully Booked
In Use
Unavailable

Even though Fully Booked, In Use, and Unavailable use red, their TEXT must clearly distinguish the reason.

Example:

Fully Booked
In Use
Unavailable

Do not treat these as the same database status.

==================================================
PHASE 11 — USER FACILITY DISPLAY
==================================================

After the changes, a user should see the actual uploaded facility photo.

If the facility is administratively available:

show its normal calendar/reservation information.

If the facility is administratively unavailable:

show:

RED — Unavailable

Do not incorrectly show a green Available indicator.

==================================================
PHASE 12 — DO NOT BREAK EXISTING FEATURES
==================================================

Preserve existing functionality, especially:

- authentication
- admin authorization
- barangay isolation
- facility creation/editing
- reservation creation
- Pending / Accepted / Rejected workflow
- user's personal reservation status
- same-barangay shared accepted bookings
- overlapping reservation requests
- duplicate-active reservation protection
- Partially Booked logic
- Fully Booked logic
- Booked logic
- In Use logic
- existing calendar navigation
- existing facility selection

Do not refactor unrelated parts of the application.

==================================================
PHASE 13 — VERIFY THE IMPLEMENTATION
==================================================

Do not stop after editing the files.

Inspect and verify the resulting code paths.

Test/verify these scenarios:

SCENARIO A — PHOTO

1. Admin creates a facility.
2. Admin uploads a facility photo.
3. Facility saves successfully.
4. Image path saves correctly.
5. User from the appropriate barangay can see the uploaded image.

SCENARIO B — EDIT PHOTO

1. Admin edits the facility.
2. Existing photo is displayed.
3. Admin uploads another photo.
4. New photo replaces the previous one correctly.
5. Editing without selecting a new photo keeps the existing image.

SCENARIO C — ADMIN UNAVAILABLE

1. Facility is Available.
2. User sees the appropriate GREEN Available state.
3. Admin changes the facility to Unavailable.
4. User refreshes/reopens the facility.
5. User sees RED Unavailable.
6. Calendar does not incorrectly show GREEN Available.
7. User cannot submit a new reservation for the disabled facility.

SCENARIO D — RE-ENABLE

1. Admin changes the facility back to Available.
2. User refreshes.
3. Normal calendar logic returns.
4. Existing accepted reservations are still calculated correctly.

SCENARIO E — BOOKED

For an enabled facility:

1. User A submits a reservation.
2. Admin accepts it.
3. Another same-barangay user sees the appropriate day-level booking state.
4. The specific accepted time shows YELLOW Booked before its scheduled time.
5. User B can still submit an overlapping request.

SCENARIO F — IN USE

When the actual accepted reservation time arrives:

The specific time period should show:

RED — In Use

It should not remain In Use after the reservation period has ended.

==================================================
FINAL REQUIREMENT
==================================================

The EXISTING PROJECT CODE is the source of truth.

Do not force assumptions from this prompt onto the project.

If the project's actual field names, classes, routes, relationships, or architecture differ from the examples/concepts above, adapt the implementation to the existing project.

Prefer modifying the existing implementation over creating duplicate controllers, helpers, models, database fields, CSS, or JavaScript.

Before making changes:
1. Analyze the existing implementation.
2. Trace the affected code paths.
3. Identify the root cause.
4. Then implement the minimum necessary changes.

After implementation, report:

1. What you inspected.
2. How the existing facility/image system worked.
3. Root cause of the Unavailable → green Available bug.
4. Files modified.
5. Files created, if any.
6. Database migration added, if any, and why it was necessary.
7. How facility photos are now stored.
8. How photo replacement works.
9. How admin-level Unavailable overrides calendar status.
10. How server-side reservation protection works.
11. How existing overlap behavior was preserved.
12. Verification/test results.

Do not say "fixed" or "working" unless you actually verified the relevant implementation.