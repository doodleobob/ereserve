<?php

namespace App\Notifications\Channels;

use App\Jobs\SendNotificationSms;
use App\Support\ReservationMailDiagnostics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueuedSimGateSms
{
    public function send(object $notifiable, object $notification): void
    {
        try {
            if (! method_exists($notification, 'toSms')) {
                return;
            }
            if (method_exists($notification, 'shouldSend') && ! $notification->shouldSend($notifiable, 'sms')) {
                return;
            }

            $message = $notification->toSms($notifiable);
            if (! is_string($message) || $message === '') {
                return;
            }
        } catch (Throwable $exception) {
            Log::warning('SMS notification could not be prepared.', [
                'notification_id' => $notification->id ?? null,
                'exception_type' => $exception::class,
                'failure_category' => ReservationMailDiagnostics::failureCategory($exception),
            ]);

            return;
        }

        DB::afterCommit(function () use ($notifiable, $notification, $message): void {
            try {
                $record = $notifiable->notifications()->whereKey($notification->id)->first();
                if ($record === null) {
                    // Security and password-reset notifications do not otherwise use
                    // the database channel. Store delivery state without the message.
                    $record = $notifiable->notifications()->create([
                        'id' => $notification->id,
                        'type' => $notification::class,
                        'data' => ['event' => 'sms_delivery_tracking', 'sms' => ['status' => 'pending']],
                    ]);
                } else {
                    $data = $record->data;
                    if (isset($data['sms']['status'])) {
                        return;
                    }
                    $data['sms'] = ['status' => 'pending'];
                    $record->data = $data;
                    $record->save();
                }

                SendNotificationSms::dispatch($record->id, $notifiable::class, (string) $notifiable->getKey(), $message)
                    ->onConnection('database')
                    ->onQueue(config('queue.connections.database.queue', 'default'));
            } catch (Throwable $exception) {
                // Keep this delivery channel independent from the email channel.
                try {
                    $record = $notifiable->notifications()->whereKey($notification->id)->first();
                    if ($record !== null) {
                        $data = $record->data;
                        $data['sms'] = ['status' => 'unknown', 'error' => 'queue_outcome_unknown'];
                        $record->data = $data;
                        $record->save();
                    }
                } catch (Throwable) {
                    // Preserve channel independence even if tracking storage is unavailable.
                }
                Log::warning('SMS notification could not be queued.', [
                    'notification_id' => $notification->id,
                    'exception_type' => $exception::class,
                    'failure_category' => ReservationMailDiagnostics::failureCategory($exception),
                ]);
            }
        });
    }
}
