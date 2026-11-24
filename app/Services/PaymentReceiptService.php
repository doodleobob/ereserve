<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

class PaymentReceiptService
{
    public function ensureForAcceptedPayment(Reservation $reservation): Payment
    {
        $payment = $reservation->payment()->lockForUpdate()->firstOrFail();
        if ($payment->payment_status !== 'paid') {
            throw new \LogicException('A receipt can only be issued for a paid reservation.');
        }
        if ($payment->receipt_reference !== null) {
            return $payment;
        }

        $year = (int) now()->format('Y');
        DB::table('payment_receipt_sequences')->insertOrIgnore(['year' => $year, 'next_number' => 1]);
        $sequence = DB::table('payment_receipt_sequences')->where('year', $year)->lockForUpdate()->first();
        $number = (int) $sequence->next_number;
        DB::table('payment_receipt_sequences')->where('year', $year)->update(['next_number' => $number + 1]);

        $payment->receipt_reference = sprintf('ER-%d-%04d', $year, $number);
        $payment->save();
        app(AuditLogger::class)->record('payment.receipt_generated', $payment, null, ['receipt_reference' => $payment->receipt_reference]);

        return $payment;
    }
}
