<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_admin_capabilities_remain_enabled_until_restricted(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $authorization = app(Authorization::class);

        foreach (AdminPermission::cases() as $permission) {
            $this->assertTrue($authorization->allows($admin, $permission), $permission->value);
        }

        $admin->restrictedPermissions()->create(['permission' => AdminPermission::PAYMENTS_MANAGE->value]);
        $this->assertFalse($authorization->allows($admin->fresh(), AdminPermission::PAYMENTS_MANAGE));
        $this->assertTrue($authorization->allows($admin->fresh(), AdminPermission::PAYMENTS_VIEW));
    }

    public function test_super_admin_is_not_restricted_by_barangay_admin_permission_rows(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        try {
            $super->restrictedPermissions()->create(['permission' => AdminPermission::PAYMENTS_MANAGE->value]);
            $this->fail('Super Admin accounts cannot receive Barangay Admin restrictions.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('user_id', $exception->errors());
        }

        $this->assertTrue(app(Authorization::class)->allows($super->fresh(), AdminPermission::PAYMENTS_MANAGE));
        $this->assertTrue(app(Authorization::class)->managesBarangay($super, 'Washington'));
    }

    public function test_admin_scope_uses_account_barangay_and_not_request_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $authorization = app(Authorization::class);

        $this->assertTrue($authorization->managesBarangay($admin, 'Taft'));
        $this->assertFalse($authorization->managesBarangay($admin, 'Washington'));
    }

    public function test_unknown_permission_keys_fail_closed_and_inactive_accounts_are_denied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $authorization = app(Authorization::class);

        $this->assertFalse($authorization->allows($admin, 'admin.superuser'));
        $this->assertFalse($authorization->allows($admin->fresh()->forceFill(['is_active' => false]), AdminPermission::RESERVATIONS_VIEW));
    }

    public function test_restrictions_are_normalized_unique_and_reject_unknown_keys(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        try {
            $admin->restrictedPermissions()->create(['permission' => 'not.a.supported.permission']);
            $this->fail('Unsupported permission identifiers must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('permission', $exception->errors());
        }

        $restriction = $admin->restrictedPermissions()->create(['permission' => AdminPermission::PAYMENTS_MANAGE->value]);
        try {
            $admin->restrictedPermissions()->create(['permission' => $restriction->permission]);
            $this->fail('Duplicate restrictions must be rejected.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('admin_permission_restrictions', 1);
        }
    }

    public function test_reservation_policy_uses_central_capability_and_scope_checks(): void
    {
        $taft = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $resident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
        $facility = Facility::create(['barangay' => 'Washington', 'slug' => 'remote-hall', 'name' => 'Remote Hall',
            'category' => 'Facility', 'description' => 'Hall', 'capacity' => 20, 'location' => 'Washington',
            'status' => 'Available', 'hourly_rate' => 100]);
        $reservation = Reservation::create(['user_id' => $resident->id, 'barangay' => 'Taft', 'facility_id' => $facility->id,
            'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => 'Facility',
            'location' => 'Washington', 'reservation_date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00',
            'purpose' => 'Meeting', 'attendees' => 5, 'status' => 'pending', 'hourly_rate_snapshot' => 100]);

        $policy = app(\App\Policies\ReservationPolicy::class);
        $this->assertFalse($policy->accept($taft, $reservation));
        $this->assertTrue($policy->manage($super, $reservation));
        $taft->restrictedPermissions()->create(['permission' => AdminPermission::RESERVATIONS_MANAGE->value]);
        $this->assertFalse($policy->manage($taft->fresh(), $reservation));
        $this->assertTrue($policy->editOwnRequest($resident, $reservation));
    }
}
