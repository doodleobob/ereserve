<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\PaymentSmsNotification;
use App\Services\PhilSMSService;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPaymentReceiptSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $paymentId)
    {
        $this->onConnection('database');
    }

    public function handle(PhilSMSService $sms): void
    {
        $notification = DB::transaction(function () {
            Payment::query()->lockForUpdate()->findOrFail($this->paymentId);
            $row = PaymentSmsNotification::query()->where('payment_id', $this->paymentId)->lockForUpdate()->firstOrFail();
            if (in_array($row->status, ['accepted', 'delivered', 'unknown'], true)) return null;
            if ($row->status === 'processing') {
                if ($row->last_attempted_at?->gt(now()->subMinutes(2))) return null;
                // The worker may have stopped after the provider accepted the SMS.
                // Preserve uncertainty and suppress another request.
                $row->status = 'unknown';
                $row->error_category = 'worker_interrupted_outcome_unknown';
                $row->save();
                return null;
            }
            $row->status = 'processing';
            $row->attempt_count++;
            $row->last_attempted_at = now();
            $row->error_category = null;
            $row->save();

            return $row;
        });
        if ($notification === null) return;

        $payment = Payment::with('reservation.user', 'reservation.facility')->findOrFail($this->paymentId);
        $reservation = $payment->reservation;
        $user = $reservation?->user;
        $phone = $user?->phone_number;
        if ($payment->payment_status !== 'paid' || $payment->receipt_reference === null) {
            $this->finish('failed', 'payment_not_receiptable');
            return;
        }
        if (! is_string($phone) || $phone === '') {
            $this->finish('failed', 'missing_mobile_number');
            return;
        }
        if ($sms->normalizeRecipient($phone) === null) {
            $this->finish('failed', 'invalid_mobile_number');
            return;
        }

        $date = Carbon::parse($reservation->reservation_date)->format('M j, Y');
        $start = Carbon::parse($reservation->start_time)->format('g:i A');
        $end = Carbon::parse($reservation->end_time)->format('g:i A');
        $paidDate = ($payment->paid_at ?? $payment->updated_at ?? $payment->created_at)->format('M j, Y');
        $resourceName = $reservation->facility?->name ?? $reservation->facility_name;
        $message = sprintf(
            "eReserve - %s\nHello, %s! Receipt %s\n%s: %s\nDate %s, %s-%s\nPaid PHP %s on %s. Thank you!",
            $reservation->managingBarangay(), $user->name, $payment->receipt_reference,
            $reservation->category === 'Equipment' ? 'Equipment' : 'Facility', $resourceName,
            $date, $start, $end, Money::format($payment->amount), $paidDate,
        );

        try {
            $result = $sms->send($phone, $message);
        } catch (Throwable $exception) {
            report($exception);
            $result = ['status' => 'unknown', 'uid' => null, 'error' => 'processing_outcome_unknown'];
        }
        $this->finish($result['status'], $result['error'], $result['uid'], $phone);
    }

    private function finish(string $status, ?string $error, ?string $uid = null, ?string $phone = null): void
    {
        DB::transaction(function () use ($status, $error, $uid, $phone) {
            $row = PaymentSmsNotification::query()->where('payment_id', $this->paymentId)->lockForUpdate()->firstOrFail();
            $row->status = $status;
            $row->provider_uid = $uid;
            $row->recipient_last_four = $phone === null ? null : substr(preg_replace('/\D+/', '', $phone) ?? '', -4);
            $row->provider_accepted_at = $status === 'accepted' ? now() : null;
            $row->error_category = $error;
            $row->save();
        });

        if ($status === 'unknown') {
            Log::warning('Payment receipt SMS outcome is unknown; automatic resend suppressed.', ['payment_id' => $this->paymentId]);
        } elseif ($status === 'failed') {
            Log::warning('Payment receipt SMS failed.', ['payment_id' => $this->paymentId, 'reason' => $error]);
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
        try {
            $this->finish('unknown', 'job_failed_outcome_unknown');
        } catch (Throwable $storageException) {
            report($storageException);
        }
    }
}
