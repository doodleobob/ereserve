You are working inside my existing Laravel eReserve project.

I recently implemented a modal design/interaction that I like.

I want that CURRENT modal design to become the consistent modal style
throughout the eReserve application.

IMPORTANT:

Do NOT ask me to manually identify every page or modal.

SEARCH THE PROJECT YOURSELF.

==================================================
TASK 1 — FIND THE REFERENCE MODAL
==================================================

First inspect the project and identify the most recently implemented
modal design/pattern.

In particular, inspect the Payments feature because its current modal
design is the style I want to reuse.

Find:

- Blade markup
- CSS/classes
- JavaScript
- open/close behavior
- header design
- close button
- body layout
- form layout
- input/select styling
- footer
- Cancel button
- primary action button
- validation errors
- loading state
- AJAX/fetch behavior
- responsive behavior
- toast behavior

Treat the current Payments modal design as the visual/interaction
reference unless the project already has a shared component that
produces that exact design.

==================================================
TASK 2 — SEARCH THE ENTIRE PROJECT
==================================================

Search the entire Laravel project for existing:

- modals
- dialogs
- popup forms
- confirmation dialogs
- create forms
- edit forms
- view/detail dialogs

Also find CRUD actions that currently navigate to a separate page but
would reasonably work as a modal.

Examples include actions such as:

Add
Create
View
Edit
Accept
Reject
Cancel
Reschedule
Deactivate
Reactivate
Confirm

Do NOT limit the search only to the examples above.

Inspect the actual project.

==================================================
TASK 3 — IDENTIFY INCONSISTENT MODALS
==================================================

Compare all discovered modals/dialogs against the current Payments
modal.

Identify which ones:

1. already use the new design
2. use an older modal design
3. use duplicated modal CSS
4. use duplicated JavaScript
5. navigate to another page for a small CRUD action that could reasonably
   use the standard modal
6. should remain a full page

==================================================
IMPORTANT EXAMPLE — FACILITY MANAGEMENT
==================================================

For example, inspect Facility Management yourself.

If:

+ Add Facility

currently opens another page, uses an older popup, or uses a different
modal design:

change it to use the same modal pattern as Payments.

Expected:

Facility Management
→ + Add Facility
→ Modal
→ Existing Add Facility form
→ Save
→ Validation
→ Create Facility
→ Close modal
→ Update Facility Management UI
→ Success toast
→ Stay on Facility Management

Do the same for Edit Facility if appropriate.

But DO NOT assume the Facility fields.

Find the existing Facility form and reuse its actual fields,
validation, relationships, image handling, and business rules.

==================================================
DO NOT INVENT FIELDS
==================================================

This is important.

For every feature:

Inspect the existing implementation first.

Do NOT invent:

- Facility fields
- Equipment fields
- Reservation fields
- Payment fields
- Official Use fields
- User fields
- Admin fields

Reuse the project's actual fields and validation.

==================================================
TASK 4 — STANDARDIZE THE MODAL SHELL
==================================================

Where appropriate, make the Payments modal design the standard eReserve
modal design.

Standardize:

- overlay/backdrop
- modal container
- width system
- border radius
- shadow
- header
- title
- close button
- body padding
- labels
- inputs
- selects
- textarea
- read-only information
- validation errors
- footer
- Cancel/Close button
- primary action button
- loading/disabled state
- responsive behavior
- open/close behavior

==================================================
REUSABLE COMPONENT
==================================================

Inspect whether a reusable Blade modal component already exists.

If one exists:

improve/reuse it instead of creating another modal system.

If one does not exist and creating one would reduce duplication:

create a reusable shared modal component/partial following the current
project architecture.

For example conceptually:

<x-modal>
    ...
</x-modal>

But DO NOT force this exact syntax.

Follow the project's existing Blade/component architecture.

==================================================
MODAL SIZES
==================================================

Do not force every modal to have exactly the same dimensions.

Use the same DESIGN with appropriate sizes.

For example:

Small:
simple confirmation

Medium:
View/Edit forms

Large:
forms containing more fields

All sizes should still look like part of the same modal system.

==================================================
CRUD ACTIONS
==================================================

Prefer the standard modal for small contextual CRUD actions.

Examples:

+ Add Facility
→ Add Facility modal

Edit Facility
→ Edit Facility modal

View Facility
→ View Facility modal

+ Add Official Use
→ Add Official Use modal

Edit Official Use
→ Edit Official Use modal

View Official Use
→ View Official Use modal

View Payment
→ Payment modal

Edit Payment
→ Payment modal

Reservation View
→ Reservation modal

Reservation Accept
→ confirmation/modal

Reservation Reject
→ confirmation/modal

Accepted Reservation Edit
→ modal

User actions
→ modal where appropriate

Admin actions
→ modal where appropriate

However, these are examples.

SEARCH THE PROJECT and determine the actual applicable places.

==================================================
KEEP FULL PAGES AS FULL PAGES
==================================================

Do NOT turn major application modules into modals.

Pages such as these should normally remain pages:

Dashboard
Reservation Management
Facility Management
Official Use
Payments
Calendar
Analytics
User Management
Admin Management
Profile/Settings

The modal is for an ACTION performed within those pages.

Example:

Facility Management = PAGE

Add Facility = MODAL

Edit Facility = MODAL

==================================================
PRESERVE BUSINESS LOGIC
==================================================

This task is primarily a UI/interaction standardization.

Do NOT change existing business rules unnecessarily.

Preserve:

- Reservation status rules
- Payment status rules
- Reservation.total
- Official Use conflict handling
- Official Use rescheduling
- Calendar behavior
- Notifications
- Facility availability
- User management rules
- Admin management rules
- authentication
- authorization
- barangay/SaaS scoping

==================================================
DO NOT BREAK WORKING FEATURES
==================================================

When converting an existing action to a modal:

reuse its existing backend logic whenever possible.

Do not rewrite controllers/services just because the UI is changing.

For example:

Existing Add Facility backend
        ↓
KEEP

Existing validation
        ↓
KEEP

Existing authorization
        ↓
KEEP

Only adapt the frontend/request/response behavior where necessary for
the modal workflow.

==================================================
AJAX / FETCH
==================================================

If the current Payments modal uses AJAX/fetch successfully, reuse that
pattern where appropriate.

Expected modal workflow:

User clicks action
        ↓
Modal opens
        ↓
User submits
        ↓
Disable submit button
        ↓
Laravel validates/processes
        ↓
JSON response
        ↓
Update current page
        ↓
Close modal
        ↓
Success toast
        ↓
Remain on current page

If validation fails:

Keep modal open.

Show field-specific errors.

Preserve entered values.

Do not navigate away.

==================================================
RESPONSIVE / PWA
==================================================

All standardized modals must work properly in the existing PWA/mobile
layout.

Check:

- small phone screens
- tablet
- desktop

Long modal content should scroll appropriately.

Do not allow:

- modal overflowing viewport
- inaccessible close button
- footer disappearing
- buttons outside screen
- horizontal form overflow
- background page scrolling incorrectly while modal is open

==================================================
AVOID DUPLICATION
==================================================

Search for duplicated:

modal CSS
modal JavaScript
overlay logic
close logic
button styles
form styles

Consolidate where it is safe.

Do NOT create a separate nearly-identical modal stylesheet for every
feature.

==================================================
IMPORTANT — DO NOT MASS REWRITE BLINDLY
==================================================

Do not simply search/replace every occurrence of "modal".

Some dialogs may have special requirements.

Inspect each usage.

Preserve special functionality where necessary while making its visual
design consistent.

==================================================
IMPLEMENTATION ORDER
==================================================

1. Inspect Payments modal.

2. Determine the reference design.

3. Search the entire project for modals/dialogs/forms.

4. Create a list internally of discovered modal/action locations.

5. Identify duplicated/inconsistent implementations.

6. Determine whether a shared component already exists.

7. Create/improve shared modal styling/component if appropriate.

8. Update applicable modals incrementally.

9. Convert small separate-page CRUD actions to modals only where it is
   safe and appropriate.

10. Preserve backend/business logic.

11. Test each affected feature.

12. Test responsive/PWA behavior.

==================================================
DO NOT
==================================================

Do NOT:

- reset the database
- reseed the database
- delete existing records
- change migrations unnecessarily
- change Reservation.total
- change Payment business rules
- change Reservation statuses
- change Official Use conflict logic
- change barangay scoping
- remove authorization
- install a new frontend framework
- introduce React
- introduce Vue
- add unnecessary dependencies
- duplicate modal CSS across features

==================================================
AFTER IMPLEMENTATION
==================================================

Give me a report containing:

1. The reference modal you found
2. All existing modal/dialog locations you found
3. Separate-page CRUD actions you found
4. Which ones were converted to modals
5. Which ones were intentionally kept as full pages and why
6. Shared Blade component/partial reused or created
7. Shared CSS changed
8. Shared JavaScript changed
9. Facility Management modal changes
10. Reservation Management modal changes
11. Payments modal changes, if any
12. Official Use modal changes
13. User/Admin Management modal changes
14. Other modals discovered and updated
15. Files created
16. Files modified
17. Tests added/updated
18. Full test results

Most importantly:

SEARCH THE ACTUAL PROJECT.

Do not rely only on the examples in this prompt.

Find all relevant modal/dialog/form implementations yourself and make
the current Payments modal design the consistent eReserve modal pattern
where appropriate.tun