<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ResourceUsagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'super_admin'], true);
    }

    public function operate(User $user, Reservation $reservation): bool
    {
        return $user->is_active && $user->role === 'admin' && $user->barangay === $reservation->managingBarangay();
    }
}
