<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResourceSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function resource(string $category, string $barangay): Facility
    {
        return Facility::create(['name' => $barangay.' '.$category, 'slug' => strtolower($barangay.'-'.$category),
            'barangay' => $barangay, 'category' => $category, 'description' => 'Community resource',
            'location' => $barangay, 'capacity' => 50, 'total_quantity' => $category === 'Equipment' ? 30 : null,
            'status' => 'Available', 'hourly_rate' => 10]);
    }

    public function test_route_type_cannot_be_overridden_and_role_scopes_are_preserved(): void
    {
        foreach (['Taft', 'Washington'] as $barangay) {
            foreach (['Facility', 'Equipment'] as $category) {
                $this->resource($category, $barangay);
            }
        }
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => 'Taft']));
            foreach (['facilities' => 'Facility', 'equipment' => 'Equipment'] as $route => $category) {
                $response = $this->get(route($route, ['barangay' => 'Washington', 'category' => strtolower($category === 'Facility' ? 'Equipment' : 'Facility')]))->assertOk();
                $items = $response->viewData('items');
                $this->assertCount($role === 'super_admin' ? 2 : 1, $items);
                $this->assertTrue($items->every(fn ($item) => $item['category'] === $category));
                if ($role !== 'super_admin') {
                    $this->assertSame($role === 'admin' ? 'Taft' : 'Washington', $items->first()['barangay']);
                }
                if ($role !== 'user') {
                    $response->assertSee('Add '.$category)->assertSee('Edit '.$category)->assertSee($category.' Details')
                        ->assertSee('name="'.($category === 'Facility' ? 'capacity' : 'total_quantity').'"', false)
                        ->assertDontSee('name="'.($category === 'Facility' ? 'total_quantity' : 'capacity').'"', false);
                }
                if ($directory = getenv('RESOURCE_SEPARATION_RENDER_DIR')) {
                    if (! is_dir($directory)) {
                        mkdir($directory, 0777, true);
                    }
                    file_put_contents($directory.'/'.$role.'-'.$route.'.html', $response->getContent());
                }
            }
        }
    }

    public function test_management_actions_keep_authorization_and_equipment_redirects(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $equipment = $this->resource('Equipment', 'Washington');
        $data = $equipment->only(['name', 'category', 'description', 'location', 'total_quantity', 'hourly_rate', 'status']);
        $data['total_quantity'] = 40;
        $this->actingAs(User::factory()->create(['role' => 'user', 'barangay' => 'Washington']));
        $this->postJson(route('facilities.store'), $data)->assertForbidden();
        $this->patchJson(route('facilities.update', $equipment->slug), $data)->assertForbidden();
        $this->deleteJson(route('facilities.destroy', $equipment->slug))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']));
        $this->get(route('facilities.show', $equipment->slug))->assertNotFound();
        $this->patchJson(route('facilities.update', $equipment->slug), $data)->assertNotFound();
        $this->deleteJson(route('facilities.destroy', $equipment->slug))->assertNotFound();
        foreach (['super_admin', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => $role === 'admin' ? 'Washington' : 'Taft']));
            $this->patch(route('facilities.update', $equipment->slug), $data)->assertRedirect(route('equipment'));
            $this->get(route('equipment.show', $equipment->slug))->assertOk()->assertSee('Back to Equipment');
        }
        $this->assertSame(40, $equipment->fresh()->total_quantity);
        $this->post(route('facilities.store'), array_merge($data, ['name' => 'New Chairs']))->assertRedirect(route('equipment'));
        $this->assertDatabaseHas('facilities', ['name' => 'New Chairs', 'barangay' => 'Washington', 'category' => 'Equipment']);
    }

    public function test_dashboard_preserves_legacy_holds_and_centralized_reservation_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $resident = User::factory()->create(['barangay' => 'Taft']);
        $facility = $this->resource('Facility', 'Taft');
        $equipment = $this->resource('Equipment', 'Taft');
        $this->resource('Equipment', 'Washington');
        foreach ([[$facility, null], [$equipment, 20], [$equipment, null]] as [$resource, $quantity]) {
            Reservation::create(['user_id' => $resident->id, 'facility_id' => $resource->id,
                'barangay' => 'Taft', 'facility_slug' => $resource->slug, 'facility_name' => $resource->name,
                'category' => $resource->category, 'location' => 'Taft', 'reservation_date' => today()->toDateString(),
                'start_time' => '09:17', 'end_time' => '10:23', 'purpose' => 'Community event',
                'attendees' => 50, 'quantity' => $quantity, 'status' => 'accepted']);
        }
        $before = Reservation::orderBy('id')->get()->toArray();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertViewHas('facilityCount', 1)->assertViewHas('equipmentCount', 1)->assertViewHas('acceptedCount', 3)
            ->assertSee('50 attendees')->assertSee('20 units')->assertSee('Legacy whole-resource hold')
            ->assertDontSee('50 units')->assertSee('9:17 AM')->assertSee('10:23 AM');
        foreach (['all' => 3, 'Facility' => 1, 'Equipment' => 2] as $category => $count) {
            $this->get(route('reservations.index', ['category' => $category]))->assertOk()
                ->assertViewHas('reservations', fn ($rows) => $rows->total() === $count);
        }
        $this->assertSame($before, Reservation::orderBy('id')->get()->toArray());
    }
}
