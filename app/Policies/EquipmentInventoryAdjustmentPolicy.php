<?php

namespace App\Policies;

use App\Models\Facility;
use App\Models\User;

class EquipmentInventoryAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'super_admin'], true);
    }

    public function adjust(User $user, Facility $facility): bool
    {
        // Facility management already explicitly permits Super Admin stock edits across barangays.
        return $user->is_active && $facility->isEquipment()
            && ($user->role === 'super_admin' || ($user->role === 'admin' && $user->barangay === $facility->barangay));
    }
}
