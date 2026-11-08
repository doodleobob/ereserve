<?php

namespace Tests\Feature;

use App\Models\AdminPermissionRestriction;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Authorization;
use App\Support\AdminPermission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminPermissionEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $this->actingAs($this->super);
    }

    public function test_active_super_admin_can_open_editor_with_only_enforced_default_capabilities(): void
    {
        $response = $this->get(route('admins.permissions', $this->admin))->assertOk()
            ->assertSee('Admin Permissions')->assertSee($this->admin->name)->assertSee('Taft')
            ->assertSee('View reservations')->assertSee('Record or update reservation payments')
            ->assertSee('Super Admin permissions are always unrestricted')
            ->assertSee('Every listed capability is enforced by backend authorization.');

        $capabilities = $response->viewData('capabilities');
        $this->assertCount(16, $capabilities);
        $values = array_map(fn (AdminPermission $permission) => $permission->value, $capabilities);
        foreach ([AdminPermission::FACILITIES_VIEW, AdminPermission::FACILITIES_MANAGE, AdminPermission::OFFICIAL_USES_MANAGE, AdminPermission::ANALYTICS_VIEW] as $permission) $this->assertContains($permission->value, $values);
        $this->assertSame([], $response->viewData('restricted'));
    }

    public function test_super_admin_can_restrict_restore_and_preserve_other_restrictions_without_duplicates(): void
    {
        $this->admin->restrictedPermissions()->create(['permission' => AdminPermission::PAYMENTS_MANAGE->value]);
        // Existing restrictions in the supported set remain until restored.
        $legacyRestriction = AdminPermissionRestriction::create([
            'user_id' => $this->admin->id,
            'permission' => AdminPermission::FACILITIES_MANAGE->value,
        ]);

        $payload = ['restricted_permissions' => [AdminPermission::RESERVATIONS_VIEW->value, AdminPermission::PAYMENTS_MANAGE->value, AdminPermission::FACILITIES_MANAGE->value]];
        $this->patchJson(route('admins.permissions.update', $this->admin), $payload)->assertOk();
        $this->patchJson(route('admins.permissions.update', $this->admin), $payload)->assertOk();
        $this->assertSame(1, $this->admin->restrictedPermissions()->where('permission', AdminPermission::RESERVATIONS_VIEW->value)->count());
        $this->assertTrue($this->admin->restrictedPermissions()->where('permission', AdminPermission::PAYMENTS_MANAGE->value)->exists());
        $this->assertDatabaseHas('admin_permission_restrictions', ['id' => $legacyRestriction->id]);

        // Restoring reservations.view leaves payments.manage and facilities.manage intact.
        $this->patchJson(route('admins.permissions.update', $this->admin), [
            'restricted_permissions' => [AdminPermission::PAYMENTS_MANAGE->value, AdminPermission::FACILITIES_MANAGE->value],
        ])->assertOk();
        $authorization = app(Authorization::class);
        $this->assertTrue($authorization->allows($this->admin->fresh(), AdminPermission::RESERVATIONS_VIEW));
        $this->assertFalse($authorization->allows($this->admin->fresh(), AdminPermission::PAYMENTS_MANAGE));
        $this->assertDatabaseHas('admin_permission_restrictions', ['id' => $legacyRestriction->id]);

        $this->patchJson(route('admins.permissions.update', $this->admin), ['restricted_permissions' => []])->assertOk();
        $this->assertTrue($authorization->allows($this->admin->fresh(), AdminPermission::PAYMENTS_MANAGE));
        $this->assertDatabaseMissing('admin_permission_restrictions', ['id' => $legacyRestriction->id]);
    }

    public function test_unknown_unsupported_and_malformed_permission_updates_are_rejected_safely(): void
    {
        foreach ([
            ['restricted_permissions' => ['made.up.permission']],
            ['restricted_permissions' => ['analytics.download']],
            ['restricted_permissions' => 'not-an-array'],
            ['restricted_permissions' => [['nested' => 'value']]],
        ] as $payload) {
            $this->patchJson(route('admins.permissions.update', $this->admin), $payload)->assertUnprocessable();
        }

        $this->assertDatabaseCount('admin_permission_restrictions', 0);
    }

    public function test_non_super_admin_inactive_super_admin_and_super_admin_targets_are_denied(): void
    {
        foreach ([$this->admin, $this->resident] as $actor) {
            $this->actingAs($actor)->get(route('admins.permissions', $this->admin))->assertForbidden();
            $this->patch(route('admins.permissions.update', $this->admin), [
                'restricted_permissions' => [AdminPermission::RESERVATIONS_VIEW->value],
            ])->assertForbidden();
        }

        $inactiveSuper = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
        $this->actingAs($inactiveSuper)->get(route('admins.permissions', $this->admin))->assertRedirect(route('login'));
        $this->actingAs($inactiveSuper)->patch(route('admins.permissions.update', $this->admin), [
            'restricted_permissions' => [AdminPermission::RESERVATIONS_VIEW->value],
        ])->assertRedirect(route('login'));

        $this->actingAs($this->super)->get(route('admins.permissions', $this->super))->assertNotFound();
        $this->patchJson(route('admins.permissions.update', $this->super), [
            'restricted_permissions' => [AdminPermission::RESERVATIONS_VIEW->value],
            'role' => 'admin', 'user_id' => $this->admin->id, 'barangay' => 'Washington',
        ])->assertNotFound();
        $this->assertSame('super_admin', $this->super->fresh()->role);
        $this->assertDatabaseCount('admin_permission_restrictions', 0);
    }

    public function test_forged_identity_fields_do_not_change_target_or_super_admin_permissions(): void
    {
        $this->patchJson(route('admins.permissions.update', $this->admin), [
            'restricted_permissions' => [AdminPermission::RESIDENTS_MANAGE->value],
            'role' => 'super_admin', 'user_id' => $this->super->id, 'barangay' => 'Washington',
        ])->assertOk();

        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame('Taft', $this->admin->fresh()->barangay);
        $this->assertTrue($this->admin->fresh()->restrictedPermissions()->where('permission', AdminPermission::RESIDENTS_MANAGE->value)->exists());
        $this->assertSame(0, $this->super->fresh()->restrictedPermissions()->count());
        $this->assertTrue(app(Authorization::class)->allows($this->super->fresh(), AdminPermission::RESIDENTS_MANAGE));
    }

    public function test_restricted_reservation_view_is_denied_directly_and_restore_keeps_barangay_scope(): void
    {
        $taftFacility = Facility::create([
            'barangay' => 'Taft', 'slug' => 'taft-permission-hall', 'name' => 'Taft Permission Hall', 'category' => 'Facility',
            'description' => 'Hall', 'capacity' => 20, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100,
        ]);
        $washingtonFacility = Facility::create([
            'barangay' => 'Washington', 'slug' => 'washington-permission-hall', 'name' => 'Washington Permission Hall', 'category' => 'Facility',
            'description' => 'Hall', 'capacity' => 20, 'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => 100,
        ]);
        foreach ([[$taftFacility, 'Taft'], [$washingtonFacility, 'Washington']] as [$facility, $barangay]) {
            Reservation::create([
                'user_id' => $this->resident->id, 'facility_id' => $facility->id, 'barangay' => $barangay,
                'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => 'Facility', 'location' => $barangay,
                'reservation_date' => '2026-12-20', 'start_time' => '09:00', 'end_time' => '10:00', 'purpose' => 'Meeting',
                'attendees' => 5, 'status' => 'pending', 'hourly_rate_snapshot' => 100,
            ]);
        }

        $this->patchJson(route('admins.permissions.update', $this->admin), [
            'restricted_permissions' => [AdminPermission::RESERVATIONS_VIEW->value],
        ])->assertOk();
        $this->actingAs($this->admin)->get(route('reservations.index'))->assertForbidden();

        $this->actingAs($this->super)->patchJson(route('admins.permissions.update', $this->admin), ['restricted_permissions' => []])->assertOk();
        $response = $this->actingAs($this->admin)->get(route('reservations.index', ['barangay' => 'Washington']))->assertOk();
        $this->assertStringContainsString('Taft Permission Hall', $response->getContent());
        $this->assertStringNotContainsString('Washington Permission Hall', $response->getContent());
    }
}
