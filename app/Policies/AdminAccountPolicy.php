<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization;

class AdminAccountPolicy
{
    public function manageAny(User $actor): bool
    {
        return app(Authorization::class)->canManageAdminAccounts($actor);
    }

    public function manage(User $actor, User $admin): bool
    {
        return $admin->role === 'admin'
            && $actor->id !== $admin->id
            && app(Authorization::class)->canManageAdminAccounts($actor);
    }
}
