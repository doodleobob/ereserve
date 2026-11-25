<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Services\SimGateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotificationSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public string $encryptedMessage;

    public function __construct(
        public string $notificationId,
        public string $notifiableType,
        public string $notifiableId,
        string $message,
    ) {
        $this->encryptedMessage = Crypt::encryptString($message);
    }

    public function handle(SimGateService $sms): void
    {
        $ready = DB::transaction(function (): bool {
            $record = DB::table('notifications')->where('id', $this->notificationId)->lockForUpdate()->first();
            if ($record === null
                || $record->notifiable_type !== $this->notifiableType
                || (string) $record->notifiable_id !== $this->notifiableId) return false;
            $data = json_decode($record->data, true) ?: [];
            $status = $data['sms']['status'] ?? 'pending';
            if (in_array($status, ['accepted', 'delivered', 'unknown', 'failed', 'skipped'], true)) return false;
            if ($status === 'processing') {
                $data['sms'] = ['status' => 'unknown', 'error' => 'worker_interrupted_outcome_unknown'];
                DB::table('notifications')->where('id', $this->notificationId)->update(['data' => json_encode($data)]);
                return false;
            }
            $data['sms'] = ['status' => 'processing', 'attempted_at' => now()->toIso8601String()];
            DB::table('notifications')->where('id', $this->notificationId)->update(['data' => json_encode($data)]);
            return true;
        });
        if (! $ready) return;

        try {
            if ($this->notifiableType !== User::class) {
                $this->skip('recipient_unavailable');
                return;
            }
            $notifiable = User::query()->find($this->notifiableId);
            if (! $notifiable) {
                $this->skip('recipient_unavailable');
                return;
            }

            $tracking = DB::table('notifications')->where('id', $this->notificationId)->first();
            if ($tracking === null) return;
            if ($reason = $this->eligibilityFailure($notifiable, $tracking)) {
                $this->skip($reason, json_decode($tracking->data, true)['event'] ?? null);
                return;
            }
            if (! is_string($notifiable->phone_number) || trim($notifiable->phone_number) === '') {
                $this->finish('failed', 'missing_mobile_number');
                return;
            }
            if ($sms->normalizeRecipient($notifiable->phone_number) === null) {
                $this->finish('failed', 'invalid_mobile_number');
                return;
            }

            $result = $sms->send($notifiable->phone_number, Crypt::decryptString($this->encryptedMessage));
            $this->finish($result['status'], $result['error'], $result['uid']);
        } catch (Throwable $exception) {
            // The provider may have accepted the request before a local failure.
            $this->finish('unknown', 'processing_outcome_unknown');
            Log::warning('SMS notification outcome is unknown; automatic resend suppressed.', [
                'notification_id' => $this->notificationId,
                'exception_type' => $exception::class,
            ]);
        }
    }

    private function finish(string $status, ?string $error = null, ?string $providerUid = null): void
    {
        DB::transaction(function () use ($status, $error, $providerUid): void {
            $record = DB::table('notifications')->where('id', $this->notificationId)->lockForUpdate()->first();
            if ($record === null) return;
            $data = json_decode($record->data, true) ?: [];
            $data['sms'] = array_filter([
                'status' => $status,
                'error' => $error,
                'provider_uid' => $providerUid,
                'completed_at' => now()->toIso8601String(),
            ], fn ($value) => $value !== null);
            DB::table('notifications')->where('id', $this->notificationId)->update(['data' => json_encode($data)]);
        });
    }

    private function eligibilityFailure(User $user, object $tracking): ?string
    {
        if ($tracking->type !== ReservationActivity::class) {
            return null;
        }

        $data = json_decode($tracking->data, true) ?: [];
        $reservationId = $data['reservation_id'] ?? null;
        if (! is_numeric($reservationId)) {
            return 'reservation_unavailable';
        }

        $reservation = Reservation::query()->with('facility')->find($reservationId);
        if (! $reservation) {
            return 'reservation_unavailable';
        }

        $adminEvents = ['submitted', 'pending_updated', 'pending_cancelled'];
        if (in_array($data['event'] ?? null, $adminEvents, true)) {
            $eventBarangay = $data['barangay'] ?? null;
            $currentReservationBarangay = ($data['event'] ?? null) === 'pending_updated'
                ? $reservation->managingBarangay()
                : $reservation->barangay;

            return $user->role === 'admin'
                && is_string($eventBarangay)
                && $user->barangay === $eventBarangay
                && $currentReservationBarangay === $eventBarangay
                    ? null
                    : 'recipient_no_longer_eligible';
        }

        return (string) $reservation->user_id === (string) $user->getKey()
            ? null
            : 'recipient_no_longer_eligible';
    }

    private function skip(string $reason, ?string $event = null): void
    {
        $this->finish('skipped', $reason);
        Log::info('SMS notification skipped.', array_filter([
            'notification_id' => $this->notificationId,
            'event' => $event,
            'reason' => $reason,
        ], fn ($value) => $value !== null));
    }

    public function failed(Throwable $exception): void
    {
        $this->finish('unknown', 'job_failed_outcome_unknown');
    }
}
