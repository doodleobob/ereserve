You are working inside my existing Laravel project:

eReserve: A Cloud-Based Public Facility Reservation and Resource
Utilization Management System.

The application ALREADY has an in-app notification system.

I now want to add EMAIL NOTIFICATIONS using the user's registered email
address.

IMPORTANT:

Do NOT replace the existing in-app notifications.

I want important events to support BOTH:

1. Existing in-app notification
2. Email notification

==================================================
INSPECT THE PROJECT FIRST
==================================================

Before changing anything, inspect:

1. Existing Notification models/classes
2. Existing database/in-app notification implementation
3. Existing notification bell
4. Existing reservation notification logic
5. Existing Official Use notification logic
6. Existing Payment notification logic
7. Existing User model
8. Registered email field
9. Existing Laravel mail configuration
10. .env mail configuration usage
11. Existing email verification implementation
12. Existing Mailables or Laravel Notifications
13. Existing queue configuration
14. Existing tests
15. Existing email templates

IMPORTANT:

Email verification is already working in this project.

Therefore, if Gmail SMTP / Laravel Mail is already correctly configured:

REUSE IT.

Do NOT create another Gmail configuration.

Do NOT hard-code Gmail credentials.

Do NOT expose SMTP credentials in source code.

==================================================
ARCHITECTURE
==================================================

Prefer using Laravel's existing Notification architecture.

If the current in-app notification already uses:

Laravel Notifications

then extend/reuse it so appropriate notifications can use:

database
mail

channels.

Conceptually:

public function via($notifiable)
{
    return ['database', 'mail'];
}

However, adapt this to the ACTUAL existing project.

Do not blindly rewrite the notification system if the current
architecture differs.

==================================================
IMPORTANT — ONE BUSINESS EVENT
==================================================

Do NOT create completely unrelated logic such as:

create in-app notification
...
somewhere else send Gmail

for the same event if Laravel Notifications can cleanly handle both.

Prefer:

Business Event
      ↓
Notification
      ↓
Database channel
      +
Mail channel

This keeps both channels consistent.

==================================================
REGISTERED EMAIL
==================================================

Send email to the user's existing registered email address.

Use the existing User model/notifiable email behavior.

Do NOT ask users to enter another email address when making a
reservation.

Do NOT create another reservation_email field.

==================================================
WHICH EVENTS SHOULD SEND EMAIL
==================================================

Email should be used for IMPORTANT reservation-related events.

Implement email for the existing events that are actually supported by
the project.

At minimum inspect and support the following where they already exist:

1. Reservation submitted
2. Reservation accepted/booked
3. Reservation rejected
4. Reservation cancelled
5. Reservation rescheduled
6. Official Use conflict
7. Official Use-caused cancellation
8. Official Use conflict resolution
9. Payment/refund status where appropriate

Do NOT invent business events that the application does not have.

==================================================
1. RESERVATION SUBMITTED
==================================================

When a resident successfully submits a reservation:

Keep the existing in-app notification if one exists.

Also send an email.

Suggested subject:

eReserve - Reservation Submitted

Suggested content:

Hello [Resident Name],

Your reservation request has been submitted successfully.

Resource:
[Resource Name]

Date:
[Reservation Date]

Time:
[Start Time] - [End Time]

Status:
Pending

Your request is awaiting review by the barangay administrator.

You can check your reservation status in eReserve.

Do NOT say the reservation is booked yet.

At this stage it is:

Pending

==================================================
2. RESERVATION ACCEPTED
==================================================

When admin accepts the reservation:

Existing in-app notification:
KEEP

Email:
SEND

Suggested subject:

eReserve - Reservation Confirmed

Suggested content:

Hello [Resident Name],

Your reservation has been confirmed.

Resource:
[Resource]

Date:
[Date]

Time:
[Start] - [End]

Total:
₱[Reservation.total]

Status:
Accepted

You can view your reservation details in eReserve.

IMPORTANT:

Use:

Reservation.total

NOT:

amount

==================================================
3. RESERVATION REJECTED
==================================================

When admin rejects a Pending reservation:

Keep existing in-app notification.

Also send email.

Subject:

eReserve - Reservation Update

Content should clearly say:

Your reservation request has been rejected.

Include:

Resource
Date
Time
Status

If the project already records a rejection reason, include it.

Do NOT invent a reason if none exists.

==================================================
4. RESERVATION CANCELLED
==================================================

When a reservation is cancelled:

Keep existing in-app notification.

Also send email.

Subject:

eReserve - Reservation Cancelled

Include:

Resource
Date
Time
Status: Cancelled

If an existing cancellation reason is stored:

include it.

Examples:

User Requested Cancellation
Official Use

==================================================
IMPORTANT — CANCELLED IS NOT REFUNDED
==================================================

Preserve the current business rule:

Reservation Status:
Cancelled

Payment Status:
Paid

can temporarily be valid.

Do NOT tell the user:

"Your payment has been refunded"

merely because the Reservation was cancelled.

Only send refund information when:

Payment Status actually becomes Refunded.

==================================================
5. RESERVATION RESCHEDULED
==================================================

When admin successfully reschedules an Accepted reservation:

Keep existing in-app notification.

Also email the resident.

Suggested subject:

eReserve - Reservation Rescheduled

Include:

Resource
New Date
New Start Time
New End Time

Status:
Accepted

If appropriate, include the previous schedule for clarity.

Do NOT create another Payment.

Existing Payment must remain associated with the Reservation.

==================================================
6. OFFICIAL USE CONFLICT
==================================================

When a newly created or rescheduled Official Use conflicts with an
existing Accepted resident reservation:

Keep the existing in-app notification.

Also send email.

Suggested subject:

eReserve - Official Use Schedule Conflict

Suggested content:

Hello [Resident Name],

Your accepted reservation is affected because the facility/resource is
required for official barangay use.

Resource:
[Resource]

Your Reservation:
[Date]
[Start Time] - [End Time]

Please open eReserve to choose your preferred action:

Reschedule

or

Cancellation

The barangay administrator will process your selected option.

IMPORTANT:

Do NOT automatically tell the user their reservation is cancelled.

Accepted conflicts remain:

Reservation Status = Accepted

until processed.

==================================================
7. PENDING RESERVATION CANCELLED BY OFFICIAL USE
==================================================

If Official Use automatically cancels a Pending reservation:

Keep the in-app notification.

Also send email.

Suggested subject:

eReserve - Reservation Cancelled Due to Official Use

Explain that the pending request was cancelled because the resource is
required for official barangay use.

Include:

Resource
Date
Time

The resident may create another reservation for another available
schedule.

==================================================
8. OFFICIAL USE CONFLICT RESOLUTION
==================================================

When the admin completes the requested resolution:

If Rescheduled:

send the normal Reservation Rescheduled notification/email.

If Cancelled:

send the normal Reservation Cancelled notification/email.

Avoid sending several redundant emails for the same final event.

==================================================
9. PAYMENT REFUNDED
==================================================

The Payments page allows the admin to edit Payment Status.

If:

Payment Status changes:

Paid
→ Refunded

send an email to the resident.

Keep/add an in-app notification for the same event if consistent with
the existing notification system.

Suggested subject:

eReserve - Payment Refunded

Suggested content:

Hello [Resident Name],

The payment for your reservation has been marked as refunded.

Reservation:
[Reservation ID]

Resource:
[Resource]

Total:
₱[Reservation.total]

Payment Status:
Refunded

Use Reservation.total.

Do NOT use Amount terminology.

==================================================
IMPORTANT — PAYMENT EDIT
==================================================

Do NOT send an email simply because the admin opened Edit Payment.

Send only after a meaningful successful status change.

Example:

Paid → Refunded

SEND.

Paid → Paid

DO NOT SEND.

==================================================
NO DUPLICATE EMAILS
==================================================

This is critical.

Do NOT send duplicate emails because:

- page was refreshed
- DataTable was reloaded
- Calendar was opened
- notification bell was opened
- Official Use status was recalculated
- relationship was loaded
- user reopened a modal

Emails must be triggered by successful BUSINESS STATE CHANGES.

==================================================
DATABASE TRANSACTIONS
==================================================

For workflows that use database transactions:

Do not send an email before the transaction successfully commits if that
could result in the user receiving an email for a rolled-back action.

Prefer dispatching notification after successful commit where supported
by the existing Laravel architecture.

Example:

Admin accepts Reservation
        ↓
Transaction succeeds
        ↓
Reservation = Accepted
        ↓
Payment successfully handled
        ↓
Commit
        ↓
Notification
        ↓
Database + Mail

Do not email:

"Reservation Confirmed"

if the database operation later fails.

==================================================
EMAIL TEMPLATE DESIGN
==================================================

Create/reuse a consistent eReserve email design.

Do NOT make every notification email look completely different.

Suggested structure:

eReserve

[Notification Title]

Hello [Name],

[Message]

Reservation Details

Resource:
...

Date:
...

Time:
...

Status:
...

[Optional action button]

Thank you,
eReserve

==================================================
EMAIL BRANDING
==================================================

Keep email design simple and professional.

Use the existing eReserve identity.

Do NOT embed large amounts of unnecessary CSS.

Make emails readable in:

Gmail
mobile email clients
desktop email clients

==================================================
ACTION BUTTON
==================================================

Where useful, provide:

View Reservation

or:

Open eReserve

using the application's configured URL.

Do NOT hard-code:

127.0.0.1

localhost

or a development IP address into email templates.

Use:

config('app.url')

or the appropriate Laravel URL generation.

==================================================
EMAIL VERIFICATION
==================================================

Inspect the existing email verification rules.

If the application requires verified email addresses for normal
authenticated use, preserve that architecture.

Do NOT break:

MustVerifyEmail

verification links

verification notice

resend verification

existing Gmail SMTP configuration

==================================================
QUEUE
==================================================

Inspect whether the project already uses Laravel queues.

If queues are configured:

prefer queued email notifications so reservation/admin actions do not
wait unnecessarily for Gmail SMTP.

If queues are NOT currently configured:

do NOT introduce a complicated queue infrastructure solely for this
feature unless necessary.

Implement safely using the project's existing architecture.

==================================================
EMAIL FAILURE
==================================================

A temporary email delivery failure should not corrupt the Reservation
transaction.

Do not roll back a valid Reservation merely because Gmail temporarily
fails after the business transaction has successfully completed.

Handle/log mail delivery failures according to existing Laravel
practices.

==================================================
SECURITY
==================================================

Never expose:

MAIL_PASSWORD
Gmail App Password
SMTP credentials

in:

Blade
JavaScript
API responses
Git-tracked source files
logs
notifications

Keep secrets in environment configuration.

==================================================
ADMIN EMAILS
==================================================

The existing system may already notify admins when a new Reservation is
submitted.

Inspect this behavior.

If email notification to admins is useful and consistent with the
existing notification architecture, send the new-reservation email only
to the appropriate barangay admin(s).

Do NOT email admins from unrelated barangays.

Preserve SaaS/tenant isolation.

==================================================
BARANGAY / SAAS SCOPING
==================================================

Notification recipients must respect tenant boundaries.

Example:

Reservation in Barangay Taft

→ notify resident
→ notify appropriate Taft admin where required

Do NOT notify an admin from another barangay.

==================================================
IN-APP + EMAIL CONSISTENCY
==================================================

For the same business event, the in-app notification and email should
communicate the same state.

Example:

Database state:

Reservation = Accepted

In-app:
Reservation Confirmed

Email:
Reservation Confirmed

Do NOT create situations such as:

In-app:
Accepted

Email:
Pending

==================================================
DO NOT REMOVE IN-APP NOTIFICATIONS
==================================================

The final architecture should support:

IN-APP
+
EMAIL

Email is an additional communication channel.

It is NOT a replacement for the notification bell.

==================================================
TESTS
==================================================

Add/update tests for:

1. Reservation submission still creates expected in-app notification.

2. Reservation submission sends email.

3. Submission email says Pending, not Accepted.

4. Accepted Reservation sends in-app notification.

5. Accepted Reservation sends confirmation email.

6. Accepted email uses Reservation.total.

7. Rejected Reservation sends email.

8. Cancelled Reservation sends email.

9. Cancelled email does not falsely claim refund.

10. Rescheduled Reservation sends email.

11. Rescheduled email contains new schedule.

12. Official Use Accepted conflict sends email.

13. Accepted conflict email does not claim cancellation.

14. Pending cancellation due to Official Use sends email.

15. Paid → Refunded sends refund email.

16. Paid → Paid does not send duplicate refund email.

17. Refund email uses Reservation.total.

18. Refreshing Payments does not resend email.

19. Reloading Calendar does not resend email.

20. Reloading Official Use does not resend email.

21. Recalculating conflict status does not resend email.

22. Correct registered user email is used.

23. Another user's email is not used.

24. Barangay isolation is preserved for admin notifications.

25. Existing email verification continues working.

26. Existing in-app notification tests continue passing.

27. Existing Reservation tests continue passing.

28. Existing Payment tests continue passing.

29. Existing Official Use tests continue passing.

==================================================
FINAL EXPECTED ARCHITECTURE
==================================================

IMPORTANT BUSINESS EVENT

        ↓

Laravel Notification

        ↓

 ┌───────────────┬───────────────┐
 │               │               │
Database/In-App  Email
Notification     Notification
 │               │
Bell             Registered Gmail/email
 │               │
 └───────────────┴───────────────┘

Both channels represent the SAME event.

==================================================
DO NOT
==================================================

Do NOT:

- remove existing in-app notifications
- create duplicate notification systems unnecessarily
- hard-code Gmail credentials
- hard-code resident email addresses
- hard-code localhost URLs in emails
- send duplicate emails on page reload
- email before a transaction that later rolls back
- claim Cancelled means Refunded
- create Payment.amount
- change Reservation.total
- create Payment for Official Use
- break email verification
- notify the wrong barangay

==================================================
AFTER IMPLEMENTATION
==================================================

Report:

1. Existing notification architecture found
2. Existing mail configuration found
3. Whether Laravel Notifications or Mailables were reused
4. Notification classes created/modified
5. In-app channels preserved
6. Email channels added
7. Reservation events that send email
8. Official Use events that send email
9. Payment/refund email behavior
10. Email templates created/modified
11. Duplicate-email prevention
12. Transaction/after-commit handling
13. Barangay scoping
14. Queue behavior, if applicable
15. Files created
16. Files modified
17. Tests created/modified
18. Complete test results

Do not claim an email was successfully delivered to Gmail merely because
a unit test passed.

Clearly distinguish:

- notification generated successfully
- Laravel mail transport accepted the message
- actual external Gmail delivery

when reporting verification results.