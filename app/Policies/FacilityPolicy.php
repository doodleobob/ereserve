<?php

namespace App\Policies;

use App\Models\Facility;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class FacilityPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true)
            && app(Authorization::class)->allows($user, AdminPermission::FACILITIES_VIEW);
    }

    public function view(User $user, Facility $facility): bool
    {
        return $this->viewAny($user)
            && app(Authorization::class)->managesFacility($user, $facility);
    }

    public function create(User $user): bool
    {
        return app(Authorization::class)->allows($user, AdminPermission::FACILITIES_MANAGE);
    }

    public function update(User $user, Facility $facility): bool
    {
        return $this->create($user)
            && app(Authorization::class)->managesFacility($user, $facility);
    }

    public function delete(User $user, Facility $facility): bool
    {
        return $this->update($user, $facility);
    }
}
