<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class ResidentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(Authorization::class)->allows($user, AdminPermission::RESIDENTS_VIEW);
    }

    public function view(User $user, User $resident): bool
    {
        return $resident->role === 'user'
            && app(Authorization::class)->allows($user, AdminPermission::RESIDENTS_VIEW)
            && app(Authorization::class)->managesBarangay($user, $resident->barangay);
    }

    public function manage(User $user, User $resident): bool
    {
        return $resident->role === 'user'
            && app(Authorization::class)->allows($user, AdminPermission::RESIDENTS_MANAGE)
            && app(Authorization::class)->managesBarangay($user, $resident->barangay);
    }
}
