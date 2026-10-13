<?php

namespace App\Notifications\Channels;

use App\Notifications\Middleware\SanitizeMailFailure;
use App\Notifications\ReservationActivity;
use App\Support\ReservationMailDiagnostics;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueuedReservationMail
{
    private array $pendingPayloads = [];

    public function capturePayload(JobQueueing $event): void
    {
        if ($event->job instanceof SendQueuedNotifications
            && $event->job->notification instanceof ReservationActivity
            && array_key_exists($event->job->notification->id, $this->pendingPayloads)) {
            // Keep Laravel's original retryable payload in memory until enqueue
            // completes. The payload itself must never be written to application logs.
            $this->pendingPayloads[$event->job->notification->id] = $event;
        }
    }

    public function send(object $notifiable, ReservationActivity $notification): void
    {
        // The database channel stays inside the business transaction. Only mail waits
        // for commit and uses the database queue through Laravel's native mail channel.
        DB::afterCommit(function () use ($notifiable, $notification) {
            // Reservation actions must never wait for SMTP, even if another feature
            // changes the application's default queue connection to sync.
            $connection = 'database';
            $queue = config('queue.connections.'.$connection.'.queue', 'default');
            $context = $notification->mailContext($notifiable) + ['connection' => $connection, 'queue' => $queue];
            $this->pendingPayloads[$notification->id] = null;
            ReservationMailDiagnostics::log('info', 'dispatching', $context);
            try {
                $jobId = Bus::dispatch((new SendQueuedNotifications($notifiable, $notification, ['mail']))
                    ->onConnection($connection)
                    ->onQueue($queue)
                    ->through([new SanitizeMailFailure]));
                ReservationMailDiagnostics::log('info', 'queued', $context + ['job_id' => $jobId]);
            } catch (Throwable $exception) {
                // Preserve native payloads in failed_jobs so queue:retry can recover
                // an enqueue failure without repeating the reservation action.
                $failedId = null;
                $pending = $this->pendingPayloads[$notification->id];
                if ($pending !== null) {
                    try {
                        $failedId = app('queue.failer')->log($connection, $queue, $pending->payload,
                            'Reservation email enqueue failed. Category: '.ReservationMailDiagnostics::failureCategory($exception));
                    } catch (Throwable $storageException) {
                        ReservationMailDiagnostics::log('error', 'failure could not be stored', $context + ['exception_type' => $storageException::class]);
                    }
                }
                ReservationMailDiagnostics::log('error', 'enqueue failed', $context + [
                    'exception_type' => $exception::class,
                    'failure_category' => ReservationMailDiagnostics::failureCategory($exception),
                    'failed_job_id' => $failedId,
                    'retry_recorded' => $failedId !== null,
                ]);
            } finally {
                unset($this->pendingPayloads[$notification->id]);
            }
        });
    }
}
