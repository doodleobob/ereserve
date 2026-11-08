<?php

namespace App\Policies;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;

class OfficialUsePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true)
            && app(Authorization::class)->allows($user, AdminPermission::OFFICIAL_USES_MANAGE);
    }

    public function create(User $user, Facility $facility): bool
    {
        $authorization = app(Authorization::class);

        return $authorization->allows($user, AdminPermission::OFFICIAL_USES_MANAGE)
            && $authorization->managesFacility($user, $facility);
    }

    public function update(User $user, OfficialUse $officialUse): bool
    {
        $authorization = app(Authorization::class);
        $facilityBarangay = Facility::query()->whereKey($officialUse->facility_id)->value('barangay');

        return is_string($facilityBarangay)
            && $authorization->allows($user, AdminPermission::OFFICIAL_USES_MANAGE)
            && $authorization->managesBarangay($user, $facilityBarangay);
    }
}
