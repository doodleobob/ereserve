<?php

namespace Tests\Feature;

use App\Models\AdminPermissionRestriction;
use App\Models\Facility;
use App\Models\User;
use App\Support\AdminPermission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdditionalAdminPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Facility $ownFacility;
    private Facility $otherFacility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->ownFacility = $this->facility('Taft', 'own-hall');
        $this->otherFacility = $this->facility('Washington', 'other-hall');
    }

    private function facility(string $barangay, string $slug): Facility
    {
        return Facility::create(['barangay' => $barangay, 'slug' => $slug, 'name' => $slug, 'category' => 'Facility', 'description' => 'Hall', 'capacity' => 20, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => 100]);
    }

    private function restrict(AdminPermission $permission): void
    {
        AdminPermissionRestriction::create(['user_id' => $this->admin->id, 'permission' => $permission->value]);
    }

    public function test_default_admin_permissions_work_with_stored_barangay_scope(): void
    {
        $this->actingAs($this->admin)->get(route('facilities'))->assertOk()->assertSee('own-hall')->assertDontSee('other-hall');
        $this->get(route('facilities.show', $this->ownFacility->slug))->assertOk();
        $this->get(route('facilities.show', $this->otherFacility->slug))->assertNotFound();
        $this->get(route('analytics'))->assertOk();
        $this->get(route('official-uses.index'))->assertOk();
    }

    public function test_facilities_view_restriction_blocks_catalog_details_and_availability_directly(): void
    {
        $this->restrict(AdminPermission::FACILITIES_VIEW);
        $this->actingAs($this->admin)->get(route('facilities'))->assertForbidden();
        $this->get(route('facilities.show', $this->ownFacility->slug))->assertForbidden();
        $this->get(route('facilities.availability', ['slug' => $this->ownFacility->slug, 'reservation_date' => '2026-12-01']))->assertForbidden();

        $this->admin->restrictedPermissions()->where('permission', AdminPermission::FACILITIES_VIEW->value)->delete();
        $this->get(route('facilities'))->assertOk();
    }

    public function test_facilities_manage_restriction_blocks_mutations_and_barangay_admin_cannot_forge_scope(): void
    {
        $this->restrict(AdminPermission::FACILITIES_MANAGE);
        $this->actingAs($this->admin)->post(route('facilities.store'), ['name' => 'New Hall', 'category' => 'Facility', 'description' => 'Hall', 'location' => 'Taft', 'capacity' => 20, 'status' => 'Available'])->assertForbidden();
        $this->admin->restrictedPermissions()->delete();

        $this->post(route('facilities.store'), ['name' => 'Forged Hall', 'category' => 'Facility', 'description' => 'Hall', 'location' => 'Washington', 'capacity' => 20, 'status' => 'Available', 'barangay' => 'Washington'])->assertSessionHasErrors('barangay');
        $this->assertDatabaseMissing('facilities', ['name' => 'Forged Hall']);
        $this->patch(route('facilities.update', $this->otherFacility->slug), ['name' => 'Changed Hall'])->assertNotFound();
    }

    public function test_official_use_and_analytics_restrictions_apply_to_direct_http_requests(): void
    {
        $this->restrict(AdminPermission::OFFICIAL_USES_MANAGE);
        $this->actingAs($this->admin)->get(route('official-uses.index'))->assertForbidden();
        $this->postJson(route('official-uses.store'), ['facility_id' => $this->ownFacility->id, 'date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'purpose' => 'Meeting'])->assertForbidden();

        $this->admin->restrictedPermissions()->delete();
        $this->restrict(AdminPermission::ANALYTICS_VIEW);
        $this->get(route('analytics'))->assertForbidden();

        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->actingAs($super)->get(route('super-admin.analytics'))->assertOk();
        $this->get(route('facilities', ['barangay' => 'Washington']))->assertOk()->assertSee('other-hall');
    }

    public function test_resident_cannot_access_admin_module_routes(): void
    {
        $resident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $this->actingAs($resident)->get(route('analytics'))->assertForbidden();
        $this->get(route('official-uses.index'))->assertForbidden();
        $this->postJson(route('official-uses.store'), [])->assertForbidden();
        $this->post(route('facilities.store'), [])->assertForbidden();
    }
}
