# Reservation email delivery

## Investigation: 13 October 2026

The active application configuration was `QUEUE_CONNECTION=database`, queue `default`,
and Gmail SMTP on port 587 (`MAIL_SCHEME=smtp`, with STARTTLS negotiated by the SMTP
transport). Configuration was not cached. Both `jobs` and `failed_jobs` existed;
both were empty at inspection. No PHP queue listener/worker was running at that time.
No reservation-mail failure appeared in the existing local logs. Credentials were
checked only for presence, never printed, changed, or tested by sending live email.

`composer run dev` already started `queue:listen`. Reservation mail already used
`SendQueuedNotifications` after `DB::afterCommit`; the notification class deliberately
does not implement `ShouldQueue`, so its database channel remains immediate.
Submission generates an administrator email for each administrator in the resource's
barangay and a confirmation to the resident; approval generates one resident email.
Existing no-op/change checks, recipient ownership checks, and the in-app-only
`official_use_decision` event were retained. There are no separate reservation
mailables or reservation event listeners sending a second copy.

Confirmed code/configuration gaps:

- Enqueue exceptions were caught after the reservation committed, with only a generic
  warning and no retryable failure record. That path could permanently lose an email.
- The Windows listener had `--timeout=0`. PCNTL is unavailable on this installation;
  a job's PHP timeout does not provide the listener's external process deadline.
  SMTP also relied on PHP's 60-second default socket timeout.
- There were no dispatch, processing, transport-acceptance, or intentional-skip logs.
  SMTP exceptions were sanitized before their diagnostic category could be recorded.
- Reservation mail inherited the application's default queue connection, so changing
  that default to `sync` could put SMTP back into the reservation request.

The earlier Gmail incidents cannot be attributed retrospectively to one of these
conditions: there was no backlog, failed job, or mail failure evidence to correlate.
The new tests reproduce enqueue loss and transient/permanent SMTP failures in isolation.

## Behavior after the fix

Mail is still queued only after a successful transaction commit. A rollback produces
neither a queued email nor a database notification. Reservation mail explicitly uses
the `database` connection, even if the application's default queue changes. There is
no synchronous fallback. Laravel's native `SendQueuedNotifications` job implements
`ShouldQueue`; only its mail channel runs in the worker. Email content, recipients,
triggering conditions, and immediate in-app notifications remain unchanged.

Each new mail job has five attempts with delays of 10, 60, 180, and 600 seconds.
Native enqueue payloads are captured before insertion. If insertion fails and failure
storage is available, the original job is saved in `failed_jobs` for `queue:retry`.
Failures before payload creation or while failure storage is unavailable are explicitly
logged with `retry_recorded=false`; those require investigation and cannot be recovered
by blindly running `queue:retry`.

The mail worker locks the existing notification record during delivery and records
`data.mail_sent_at` after transport acceptance. A replay of the same notification ID
skips sending; a new legitimate event retains its own notification ID. This receipt is
excluded from notification API display data. No schema changes are required. The lock
is on the notification row, not the reservation/payment rows; marking that particular
notification read may wait briefly while its email is being sent.

SMTP cannot guarantee exactly-once inbox delivery: if a provider accepts a message and
the connection/process/database fails before acceptance can be recorded, a retry can
still send another copy. The receipt prevents replays of recorded successful sends.
The application cannot establish Gmail inbox delivery from SMTP acceptance alone.

## Local Windows development

Restart the existing development command after applying these changes:

```powershell
composer run dev
```

It runs one `queue:listen database --sleep=1 --tries=5 --backoff=10 --timeout=75`
process, and `concurrently` restarts failed processes after one second. The listener
uses the database connection's configured queue name (`DB_QUEUE`, default `default`),
matching every reservation-mail job. It launches one worker child at a time. Keep this
terminal running while using the app; do not start another manual worker alongside it.

The relevant nonsecret defaults are:

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=90
QUEUE_FAILED_DRIVER=database-uuids
MAIL_MAILER=smtp
MAIL_TIMEOUT=20
LOG_LEVEL=info
```

Existing `.env` already has `QUEUE_CONNECTION=database`; no credential or environment
changes were needed. `MAIL_TIMEOUT` is optional and defaults to
20 seconds. `DB_QUEUE_CONNECTION` and `DB_QUEUE_TABLE` optionally override the queue
database connection/table. SMTP configuration also reads `MAIL_SCHEME`, `MAIL_HOST`,
`MAIL_PORT`, `MAIL_URL`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_EHLO_DOMAIN`,
`MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`; never paste credential values into logs or
support messages. This mail configuration uses `MAIL_SCHEME`, not `MAIL_ENCRYPTION`.

The intended timeout ordering is SMTP I/O 20s < job 60s < Windows child process 75s
< database reservation expiry (`retry_after`) 90s. SMTP I/O timeout applies to socket
operations, while the external listener deadline bounds the whole child process.
If a child hangs, the listener exits on its process timeout, the development process
manager restarts it, and the job becomes eligible for retry after expiry.
Restart processes after configuration changes. If a deployment uses cached config,
rebuild that cache as part of its deployment procedure.

The existing migration `database/migrations/0001_01_01_000002_create_jobs_table.php`
defines `jobs`, `job_batches`, and `failed_jobs`. Both required live tables were
confirmed present. No migration command is required for this installation and none
was run against the application database.

## Production

Run a dedicated worker under Supervisor or systemd on Linux with PCNTL installed.
Do not use `composer run dev` as a production process manager. For the current database
connection and `default` queue, a Supervisor example is:

```ini
[program:ereserve-mail-worker]
directory=/var/www/ereserve
command=/usr/bin/php artisan queue:work database --queue=default --sleep=1 --tries=5 --backoff=10 --timeout=60 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/log/ereserve-queue.log
```

Adjust paths/user and replace `default` with `DB_QUEUE` if configured differently. Keep
`retry_after` greater than the worker timeout. Use `php artisan queue:restart` during
deployment so long-lived workers reload code/configuration; the process manager starts
their replacements. Monitor process uptime, oldest pending-job age, and failed-job
counts. Windows hosting needs a persistent service manager and the bounded listener;
do not rely on PCNTL signal timeouts there. These requirements follow the
[Laravel queue worker documentation](https://laravel.com/framework/docs/13.x/queues).

## Diagnosis and recovery

Application logs contain reservation/notification/recipient IDs, event names, queue
and job identifiers, attempts, exception class, and a fixed failure category. They
never include recipient email addresses, SMTP credentials, provider exception messages,
or email bodies. Keep logs at `info` to retain the full lifecycle:

1. `Reservation email dispatching.` and `queued.`: committed event handed to the queue.
2. `processing.`: worker picked up the job, with its attempt number.
3. `accepted by transport.`: the transport accepted the message, not proof of inbox arrival.
4. `processed.`: worker completed; inspect `skipped.` if there was no transport acceptance.
5. `attempt failed.`: retryable failure; category such as authentication, rate limit,
   timeout, connection, recipient, or other. `delivery failed.` marks exhausted attempts.
6. `enqueue failed.`: inspect `retry_recorded` and `failed_job_id` before retrying.

Use the native read-only failure listing:

```shell
php artisan queue:failed
```

After correcting a diagnosed configuration/provider problem, retry only the selected
failure UUID with `php artisan queue:retry <uuid>`. The native retry reuses the mail-only
job; it does not repeat a reservation action or create another in-app notification.
Failed-job payloads contain reservation snapshots and must be treated as private data.
Do not flush failures or resubmit reservations as a mail workaround.

If the job is accepted by the transport but the message is absent, investigate the
sender's bounce notices and the recipient's spam folder/provider tracing. For
authentication, throttling, or recipient rejections, use Google's
[SMTP error reference](https://support.google.com/mail/answer/3726730?hl=en).
An exhausted queue retry budget needs operator follow-up; it does not establish that
Gmail delivered the message. No live messages, retries, application-database migrations,
or application-data deletions were performed during this investigation.

## Changed files

| File | Change |
| --- | --- |
| `composer.json` | Single database listener, bounded Windows timeout, shorter idle polling, worker restart settings. |
| `config/mail.php` | Explicit, configurable SMTP socket timeout. |
| `app/Notifications/Channels/QueuedReservationMail.php` | Database-only asynchronous mail, matching queue selection, dispatch logs, retryable enqueue-failure records. |
| `app/Notifications/Middleware/SanitizeMailFailure.php` | Processing/attempt logs, duplicate-delivery lock and receipt check, sanitized exceptions. |
| `app/Notifications/ReservationActivity.php` | Retry schedule, transport receipt, safe success/failure/skip context. |
| `app/Support/ReservationMailDiagnostics.php` | Safe logging and fixed failure categories. |
| `app/Providers/AppServiceProvider.php` | Register native queue-payload capture. |
| `tests/Feature/ReservationEmailNotificationTest.php` | Database workers for content assertions; submission/approval with a sync application default; custom queue/listener matching; retry, recovery, receipts, logging, and timeout coverage. |
| `docs/reservation-email-operations.md` | Findings, configuration, diagnostics, recovery, and delivery limits. |

Tests use the suite's guarded in-memory SQLite database and fake/array mail transport.
Existing work on login redirects, PWA tests, seed data, and deleted reports was left intact.

## Validation

`php -d xdebug.mode=off artisan test --compact` passed **521 tests and 7,055
assertions**, including all **30 reservation-email tests**. The targeted email run
also passed independently (30 tests, 376 assertions). Coverage includes after-commit
dispatch and rollback, submission/approval recipients, snapshots, tenant changes,
real database-queue processing, delayed retries, exhausted failures, enqueue recovery,
duplicate replays, logging failures, and timeout ordering. PHP syntax checks passed for
all seven changed PHP files, and `git diff --check` passed.

`node --test tests/Frontend/*.test.cjs` passed **49 tests**, including private-page
network-only/no-store PWA behavior. `node --check public/sw.js` also passed. The
service worker and its security protections were unchanged by the email work.
