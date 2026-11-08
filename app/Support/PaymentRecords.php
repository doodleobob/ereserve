<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class PaymentRecords
{
    // Caller locks the Reservation and holds a transaction.
    public static function recordAcceptance(Reservation $reservation, User $actor): void
    {
        $payment = $reservation->payment()->lockForUpdate()->first();
        if ($payment === null) {
            $payment = $reservation->payment()->create(['amount' => $reservation->total, 'payment_status' => 'paid']);
            app(AuditLogger::class)->record('payment.acceptance_recorded', $payment->load('reservation.facility'), null, ['amount' => $payment->amount, 'payment_status' => $payment->payment_status]);
        } elseif ($payment->payment_status === 'pending') {
            self::changeStatus($payment, 'paid', $actor);
        } elseif ($payment->payment_status !== 'paid') {
            throw ValidationException::withMessages(['payment' => 'This payment is already closed. Review its history before accepting this reservation.']);
        }
    }

    // Caller locks the Reservation and holds a transaction, as with acceptance.
    public static function recordCancellation(Reservation $reservation, User $actor): void
    {
        $payment = $reservation->payment()->lockForUpdate()->first();
        if ($payment === null) {
            // A missing receipt is not evidence of money collected.
            $payment = $reservation->payment()->create(['amount' => $reservation->total ?? '0.00', 'payment_status' => 'pending']);
            app(AuditLogger::class)->record('payment.cancellation_recorded', $payment->load('reservation.facility'), null, ['amount' => $payment->amount, 'payment_status' => $payment->payment_status]);
        }

        $status = match ($payment->payment_status) {
            'paid' => 'refund_pending',
            'pending' => 'cancelled',
            default => $payment->payment_status,
        };
        self::changeStatus($payment, $status, $actor);
    }

    public static function changeStatus(Payment $payment, string $status, User $actor): void
    {
        if (! in_array($status, $payment->allowedStatuses(), true)) {
            throw ValidationException::withMessages(['payment_status' => 'This payment status transition is not permitted.']);
        }
        if ($status === $payment->payment_status) {
            return;
        }
        $beforeStatus = $payment->payment_status;
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
        app(AuditLogger::class)->record('payment.status_changed', $payment->load('reservation.facility'), ['payment_status' => $beforeStatus], ['payment_status' => $status, 'amount' => $payment->amount]);
        if ($status === 'refunded') {
            $reservation = $payment->reservation;
            $reservation?->user?->notify(new ReservationActivity($reservation, 'payment_refunded'));
        }
    }

    public static function changeStatusByAdministrator(Payment $payment, string $status, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $payment);
        self::changeStatus($payment, $status, $actor);
    }

    /** Caller holds a transaction; locks and reauthorizes the reservation before correction. */
    public static function correctReservationTotal(Reservation $reservation, User $actor, string $total, string $reason): bool
    {
        $reservation = Reservation::query()->lockForUpdate()->with('facility')->findOrFail($reservation->id);
        Gate::forUser($actor)->authorize('correctPayment', $reservation);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();

        $before = $reservation->total_payment;
        $reservation->total_payment = $total;
        if (! $reservation->isDirty('total_payment')) {
            return false;
        }

        $entry = [
            'type' => 'total_correction', 'before' => ['total_payment' => $before],
            'after' => ['total_payment' => $reservation->total_payment],
            'actor_id' => $actor->id, 'actor_name' => $actor->name,
            'reason' => $reason, 'at' => now()->toIso8601String(),
        ];
        $payment = $reservation->payment()->lockForUpdate()->first();
        if ($payment !== null) {
            $payment->history = [...($payment->history ?? []), $entry];
            $payment->revision = ($payment->revision ?? 0) + 1;
            $payment->save();
        } else {
            $reservation->change_history = [...($reservation->change_history ?? []), [
                'action' => 'payment_correction', ...$entry,
            ]];
        }
        $reservation->save();
        app(AuditLogger::class)->record('reservation.payment_corrected', $reservation->load('facility'), ['total_payment' => $before], ['total_payment' => $reservation->total_payment], $reason);

        return true;
    }
}
