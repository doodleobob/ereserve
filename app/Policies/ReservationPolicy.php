<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function manage(User $user, Reservation $reservation): bool
    {
        return $user->role === 'super_admin'
            || ($user->role === 'admin' && $reservation->managingBarangay() === $user->barangay);
    }
}
