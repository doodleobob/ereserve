<?php

namespace App\Notifications\Channels;

use App\Notifications\Middleware\SanitizeMailFailure;
use App\Notifications\ReservationActivity;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueuedReservationMail
{
    public function send(object $notifiable, ReservationActivity $notification): void
    {
        // The database channel stays inside the business transaction. Only mail waits
        // for commit and uses the existing queue, through Laravel's native mail channel.
        DB::afterCommit(function () use ($notifiable, $notification) {
            try {
                Bus::dispatch((new SendQueuedNotifications($notifiable, $notification, ['mail']))
                    ->onConnection(config('queue.default'))
                    ->through([new SanitizeMailFailure]));
            } catch (Throwable $exception) {
                // Covers enqueue failures and delivery failures with the sync driver.
                // The committed reservation and its in-app notification remain valid.
                // SyncQueue already invokes the notification's failed hook.
                if (config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
                    $notification->failed($exception);
                }
            }
        });
    }
}
