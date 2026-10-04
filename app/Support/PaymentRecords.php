<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use Illuminate\Validation\ValidationException;

class PaymentRecords
{
    // Caller locks the Reservation and holds a transaction.
    public static function recordAcceptance(Reservation $reservation, User $actor): void
    {
        $payment = $reservation->payment()->lockForUpdate()->first();
        if ($payment === null) {
            $reservation->payment()->create(['amount' => $reservation->total, 'payment_status' => 'paid']);
        } elseif ($payment->payment_status === 'pending') {
            self::changeStatus($payment, 'paid', $actor);
        } elseif ($payment->payment_status !== 'paid') {
            throw ValidationException::withMessages(['payment' => 'This payment is already closed. Review its history before accepting this reservation.']);
        }
    }

    public static function changeStatus(Payment $payment, string $status, User $actor): void
    {
        if (! in_array($status, $payment->allowedStatuses(), true)) {
            throw ValidationException::withMessages(['payment_status' => 'This payment status transition is not permitted.']);
        }
        if ($status === $payment->payment_status) {
            return;
        }
        $payment->history = [...($payment->history ?? []), [
            'before' => $payment->payment_status, 'after' => $status,
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'at' => now()->toIso8601String(),
        ]];
        $payment->payment_status = $status;
        $payment->revision = ($payment->revision ?? 0) + 1;
        if ($status === 'refunded') {
            $payment->refunded_at = now();
            $payment->refunded_by = $actor->id;
        }
        $payment->save();
        if ($status === 'refunded') {
            $reservation = $payment->reservation;
            $reservation?->user?->notify(new ReservationActivity($reservation, 'payment_refunded'));
        }
    }
}
