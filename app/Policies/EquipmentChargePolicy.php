<?php

namespace App\Policies;

use App\Models\EquipmentCharge;
use App\Models\User;

class EquipmentChargePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === 'admin';
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->role === 'admin';
    }

    public function manage(User $user, EquipmentCharge $charge): bool
    {
        return $user->is_active && $user->role === 'admin'
            && $charge->usage?->barangay === $user->barangay;
    }

    public function view(User $user, EquipmentCharge $charge): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return match ($user->role) {
            'admin' => $charge->usage?->barangay === $user->barangay,
            'user' => $charge->usage?->reservation?->user_id === $user->id,
            default => false,
        };
    }
}
