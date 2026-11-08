<?php

namespace App\Policies;

use App\Models\EquipmentCharge;
use App\Models\ResourceUsage;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class EquipmentChargePolicy
{
    public function viewAny(User $user): bool
    {
        return app(Authorization::class)->allows($user, AdminPermission::EQUIPMENT_CHARGES_MANAGE);
    }

    public function create(User $user): bool
    {
        return app(Authorization::class)->allows($user, AdminPermission::EQUIPMENT_CHARGES_MANAGE);
    }

    public function manage(User $user, EquipmentCharge $charge): bool
    {
        $usage = ResourceUsage::query()->with(['facility', 'reservation'])->find($charge->resource_usage_id);
        $facility = $usage?->facility;
        $reservation = $usage?->reservation;
        $authorization = app(Authorization::class);

        return $usage !== null
            && $usage->resource_type === 'Equipment'
            && $facility !== null
            && $facility->category === 'Equipment'
            && $reservation !== null
            && $reservation->facility_id === $facility->id
            && $authorization->allows($user, AdminPermission::EQUIPMENT_CHARGES_MANAGE)
            && $authorization->managesFacility($user, $facility);
    }

    public function recordPayment(User $user, EquipmentCharge $charge): bool
    {
        return $this->manage($user, $charge);
    }

    public function view(User $user, EquipmentCharge $charge): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->role === 'user'
            ? $charge->usage?->reservation?->user_id === $user->id
            : $this->manage($user, $charge);
    }
}
