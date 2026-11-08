<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AdminPermission;

/** Central role capability and record scope checks. */
class Authorization
{
    public function canManageAdminAccounts(User $user): bool
    {
        return $user->is_active && $user->role === 'super_admin';
    }

    public function allows(User $user, AdminPermission|string $permission): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $key = $permission instanceof AdminPermission
            ? $permission->value
            : AdminPermission::tryFrom($permission)?->value;
        if ($key === null) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role !== 'admin') {
            return false;
        }

        // Permission restriction rows are valid only for Barangay Admin accounts.
        if ($user->restrictedPermissions()->exists()) {
            $invalid = $user->restrictedPermissions()->whereNotIn('permission', AdminPermission::values())->exists();
            if ($invalid) {
                return false;
            }
        }

        return ! $user->restrictedPermissions()->where('permission', $key)->exists();
    }

    public function managesBarangay(User $user, string $barangay): bool
    {
        return $user->is_active && match ($user->role) {
            'super_admin' => true,
            'admin' => $user->barangay === $barangay,
            default => false,
        };
    }

    public function managesReservation(User $user, Reservation $reservation): bool
    {
        return $this->managesBarangay($user, $reservation->managingBarangay());
    }

    public function canManageReservation(User $user, Reservation $reservation, AdminPermission $permission): bool
    {
        return $this->allows($user, $permission) && $this->managesReservation($user, $reservation);
    }

    public function managesFacility(User $user, Facility $facility): bool
    {
        return $this->managesBarangay($user, $facility->barangay);
    }
}
