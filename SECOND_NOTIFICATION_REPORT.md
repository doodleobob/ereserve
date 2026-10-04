# In-app and email notifications

Important reservation events now use one `ReservationActivity` notification for the existing database bell and an additional queued email to the registered address. Existing Gmail SMTP, email verification, reservation/payment rules, and tenant ownership are retained.

## Architecture and configuration

1. **Existing notification architecture.** `App\Notifications\ReservationActivity` already used Laravel's database channel. `NotificationController` scopes the bell to the current user and managing barangay, translates historical payment wording, and supports opening/reading notifications. The existing bell Blade and polling JavaScript are unchanged.

2. **Existing mail configuration.** `config/mail.php` already reads the environment's SMTP settings. The local environment selects Gmail SMTP on port 587 and the existing sender configuration. Those settings are reused without changing `.env`, copying credentials, or adding another Gmail configuration. Email action links combine `config('app.url')` with a relative Laravel reservation route, so they do not use the HTTP request's Host header or a hard-coded development address. The currently configured application origin is a local development URL; external deployment should configure its real origin through `APP_URL`.

3. **Laravel Notifications reused.** No separate Mailable or duplicate business-event dispatcher was added. One notification builds both channel contents. The database channel runs synchronously inside the original transaction. The small `QueuedReservationMail` notification channel schedules Laravel's own `SendQueuedNotifications` job for its native `mail` channel after commit. This preserves the existing transactional in-app writes without queuing the bell or changing global queue settings.

4. **Classes modified/created.** `ReservationActivity` now holds plain event-time reservation values, constructs the shared `MailMessage`, defines retries/backoff, checks queued recipient ownership, and logs sanitized failure context. `QueuedReservationMail` handles after-commit dispatch through the configured queue. `SanitizeMailFailure` job middleware replaces provider exceptions with a generic retryable exception without retaining private messages or an exception chain. Plain snapshots prevent a delayed acceptance email from changing to Cancelled, a corrected total, or a later schedule when the worker reloads data.

5. **In-app channel preserved.** Existing submitted/admin, accepted, total correction, cancellation, Official Use cancellation/conflict, and resident-preference notifications retain their existing bell behavior. Resident submission, rejection, rescheduling, and refund notifications are added to the same class. `submission_confirmed` is the resident submission event; the existing `submitted` event remains the admin event because the bell intentionally hides that admin event from residents.

6. **Email channel added.** Supported events use the queueing mail channel alongside `database`. Official Use resident preferences (`official_use_decision`) remain in-app only. They are an intermediate administrative preference; the resident receives the normal final cancellation/rescheduling email when processed.

## Events and behavior

7. **Reservation events.** Submission emails report Pending and awaiting review. Acceptance emails report Accepted and use `Reservation.total`; the existing in-app title remains Reservation Booked. Rejection emails state Rejected without inventing an unstored reason. Cancellation emails include the stored reason/notes without claiming a refund. Rescheduling emails include the new schedule and previous schedule, including overnight end dates, while retaining the existing payment. Meaningful accepted-reservation total corrections also receive the existing Total Paid Updated event by email.

8. **Official Use events.** Existing accepted conflicts receive a conflict email inviting Reschedule or Cancellation while remaining Accepted. Automatically cancelled pending requests receive the dedicated cancellation-due-to-Official-Use email. Newly affected reservations after an Official Use edit use the same notifications. Recalculations and unchanged overlaps do not renotify. Reintroducing a resolved conflict creates one notification for that new occurrence. Admin resolution produces the normal final reservation email once.

9. **Payment/refund behavior.** `PaymentRecords::changeStatus` notifies only after a successful transition to Refunded. The existing transition rules allow this from Paid. Paid-to-Paid and Refunded-to-Refunded remain silent; opening or refreshing Payments sends nothing. Both channels use `Reservation.total`, even when the existing legacy `Payment.amount` differs. The legacy payment schema is unchanged. Cancelled reservations can retain Paid payments until a separate refund is recorded.

10. **Email template.** `emails/reservation-activity.blade.php` provides one eReserve HTML layout with a greeting, event message, reservation detail table, configured-origin View Reservation button, and signature. Blade escapes names, messages, and notes. The existing security-code email template is unchanged.

11. **Duplicate prevention.** Notifications are emitted by successful write workflows rather than reads, relationships, or model observers. Existing locked status checks prevent repeated acceptance/rejection/cancellation. Schedule comparison suppresses unchanged reschedules, decimal dirty checks suppress unchanged totals, payment transition checks suppress unchanged statuses, and existing Official Use conflict records suppress unchanged overlaps. A mail job processes only the mail channel, so a mail retry does not repeat the in-app notification.

12. **Transactions and failures.** The in-app write remains part of the business transaction. `DB::afterCommit` delays mail dispatch until the outer business transaction succeeds and discards callbacks on rollback. Enqueue or synchronous delivery failures are caught after commit and logged with reservation ID, event, and exception class rather than provider messages or credentials. Mail-job middleware also sanitizes exceptions before they reach worker logs and `failed_jobs`. Asynchronous SMTP failures follow Laravel's normal job retry/failed-job handling and cannot roll back the reservation. Notification failure hooks also log sanitized context.

13. **Barangay scoping.** Submission admins are selected using the reservation/resource's managing barangay, including reservations made by residents of another barangay. No unrelated admin or super admin receives submission email. Queued admin mail rechecks role and barangay at processing time; resident mail checks the reservation's user ID and uses Laravel's existing registered-email routing. Existing authenticated/verified routes and `MustVerifyEmail` behavior are unchanged.

14. **Queue behavior.** The existing default database queue and jobs/failed-jobs tables are reused. Jobs allow three attempts, a 60-second timeout, and retry backoffs of 60 and 300 seconds. The configured sync connection remains supported for tests and installations that select it. Production mail requires the existing queue worker, such as `php artisan queue:work`; `composer dev` already starts a queue listener. No production worker or queued production messages were processed during verification.

## Files and verification

15. **Files created.**

    - `app/Notifications/Channels/QueuedReservationMail.php`
    - `app/Notifications/Middleware/SanitizeMailFailure.php`
    - `resources/views/emails/reservation-activity.blade.php`
    - `tests/Feature/ReservationEmailNotificationTest.php`
    - `SECOND_NOTIFICATION_REPORT.md`

16. **Files modified.**

    - `app/Notifications/ReservationActivity.php`
    - `app/Http/Controllers/ReservationController.php`
    - `app/Support/PaymentRecords.php`
    - `tests/Feature/PaymentNotificationTest.php`
    - `tests/Feature/CrossBarangayReservationTest.php`
    - `tests/Feature/ReservationDataTableTest.php`

    The supplied `database/specs/second_notification.md` is unchanged. No migration, package, SMTP setting, verification handler, or payment schema change was needed.

17. **Tests created/updated.** Nineteen new feature tests exercise rendered messages through Laravel's array mail transport, both channels, registered recipients, tenant isolation, event wording/totals, overnight rescheduling and payment preservation, Official Use creation/edit/resolution, read/no-op suppression, rollback, actual database job serialization/execution, event snapshots, changed admin ownership, enqueue failures, sync delivery failures, and asynchronous mail retries. Existing notification tests now account for resident submission/rejection notices and select accepted notices explicitly. Cross-barangay and reservation-table tests account for the added submission/rescheduling notices.

18. **Complete final test results.**

    | Check | Final result |
    | --- | --- |
    | Full PHP suite: `php artisan test --compact` | **284 passed, 3,815 assertions**, 110.743 seconds; no failures/errors |
    | New email feature tests, included in full suite | **19 passed** |
    | Existing PHP tests, included in full suite | **265 passed**, including notifications, reservations, payments, Official Use, verification, password reset, and two-factor authentication |
    | Notification JavaScript: `node --test tests/Frontend/notifications.test.cjs` | **4 passed**, no failures |
    | Pint for all 9 created/modified PHP files | **Passed** |
    | PHP syntax checks for the new channel, middleware, feature tests, and modified notification | **Passed** |
    | `git diff --check` | **Passed** |

    Laravel's PHP test runner required execution outside the Windows sandbox because its subprocess could not access the project directory. Database tests are guarded to use isolated in-memory SQLite. The final run includes corrected expectations for the added submission/rescheduling bell events and the mail exception sanitization middleware.

**Delivery verification:** Tests verify notification generation, queued-job processing, rendered recipients/content, and acceptance by Laravel's test array mail transport. No live SMTP submission or actual external Gmail inbox delivery was tested, and neither is claimed.
