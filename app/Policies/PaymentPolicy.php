<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class PaymentPolicy
{
    public function update(User $user, Payment $payment): bool
    {
        // Resolve scope from fresh persisted reservation and facility records;
        // the denormalized reservation barangay is not authoritative when a
        // facility is linked.
        $reservation = Reservation::find($payment->reservation_id);
        $barangay = $reservation?->facility_id
            ? Facility::query()->whereKey($reservation->facility_id)->value('barangay')
            : $reservation?->barangay;

        $authorization = app(Authorization::class);

        return $reservation !== null
            && is_string($barangay)
            && $authorization->allows($user, AdminPermission::PAYMENTS_MANAGE)
            && $authorization->managesBarangay($user, $barangay);
    }
}
