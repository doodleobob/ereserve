<?php

namespace App\Notifications\Middleware;

use App\Mail\Transports\GmailApiTransportException;
use App\Support\ReservationMailDiagnostics;
use Closure;
use RuntimeException;
use Throwable;

class SanitizeMailFailure
{
    public function handle(object $job, Closure $next): mixed
    {
        $recipient = $job->notifiables->first();
        $context = $job->notification->mailContext($recipient) + [
            'job_id' => $job->job?->getJobId(), 'attempt' => $job->attempts(),
            'connection' => $job->connection, 'queue' => $job->job?->getQueue(),
        ];
        ReservationMailDiagnostics::log('info', 'processing', $context);

        if ($recipient === null) {
            ReservationMailDiagnostics::log('info', 'skipped', $context + ['reason' => 'recipient_deleted']);

            return null;
        }

        try {
            // Serialize duplicate deliveries using the existing in-app record;
            // this transaction runs in the mail worker, after the reservation commit.
            $result = $recipient->getConnection()->transaction(function () use ($recipient, $job, $next, $context) {
                $record = $recipient->notifications()->whereKey($job->notification->id)->lockForUpdate()->first();
                if ($record === null) {
                    throw new RuntimeException('Reservation notification record is missing.');
                }
                if (isset($record->data['mail_sent_at'])) {
                    ReservationMailDiagnostics::log('info', 'skipped', $context + ['reason' => 'already_sent']);

                    return null;
                }

                return $next($job);
            });
            ReservationMailDiagnostics::log('info', 'processed', $context);

            return $result;
        } catch (Throwable $exception) {
            $diagnostics = $exception instanceof GmailApiTransportException
                ? $exception->diagnosticContext()
                : [
                    'exception_class' => $exception::class,
                    'error_category' => ReservationMailDiagnostics::failureCategory($exception),
                ];

            $failureContext = [
                'exception_type' => $diagnostics['exception_class'],
                'exception_class' => $diagnostics['exception_class'],
                'failure_category' => $diagnostics['error_category'],
            ];
            if (isset($diagnostics['operation'])) {
                $failureContext['operation'] = $diagnostics['operation'];
            }

            ReservationMailDiagnostics::log('warning', 'attempt failed', $context + $failureContext);
            // SMTP errors may include authentication details. Do not retain the
            // provider exception in worker logs, failed_jobs, or exception chains.
            throw new RuntimeException('Reservation email delivery failed.');
        }
    }
}
