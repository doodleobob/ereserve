<?php

namespace App\Policies;

use App\Models\Facility;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class EquipmentInventoryAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(Authorization::class)->allows($user, AdminPermission::INVENTORY_VIEW);
    }

    public function adjust(User $user, Facility $facility): bool
    {
        return $facility->isEquipment()
            && app(Authorization::class)->allows($user, AdminPermission::INVENTORY_ADJUST)
            && app(Authorization::class)->managesFacility($user, $facility);
    }
}
