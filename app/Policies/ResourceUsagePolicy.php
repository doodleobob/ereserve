<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class ResourceUsagePolicy
{
    public function viewAny(User $user): bool
    {
        return app(Authorization::class)->allows($user, AdminPermission::RESOURCE_USAGE_VIEW);
    }

    public function operate(User $user, Reservation $reservation): bool
    {
        return app(Authorization::class)->canManageReservation($user, $reservation, AdminPermission::RESOURCE_USAGE_OPERATE);
    }
}
