<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossBarangayReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function resource(array $attributes = []): Facility
    {
        return Facility::create(array_merge([
            'barangay' => 'Taft', 'slug' => 'taft-court', 'name' => 'Taft Court',
            'category' => 'Facility', 'description' => 'Community resource', 'capacity' => 50,
            'location' => 'Town center', 'status' => 'Available', 'hourly_rate' => '100.00',
            'reservation_access' => 'all_registered_users',
        ], $attributes));
    }

    private function booking(): array
    {
        return ['reservation_date' => today()->addDay()->toDateString(), 'start_time' => '08:00',
            'end_time' => '09:00', 'purpose' => 'Community event', 'attendees' => 10,
            'barangay' => 'Washington'];
    }

    public function test_browsing_defaults_home_and_filters_without_changing_membership(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);
        $this->resource();
        $this->resource(['barangay' => 'Washington', 'slug' => 'home', 'name' => 'Home Court']);
        $this->actingAs($user)->get(route('facilities'))->assertOk()->assertSee('Home Court')->assertDontSee('Taft Court');
        $this->get(route('facilities', ['barangay' => 'Taft']))->assertOk()->assertSee('Taft Court')->assertDontSee('Home Court');
        $this->get(route('facilities', ['barangay' => 'Unknown']))->assertSessionHasErrors('barangay');
        $this->assertSame('Washington', $user->fresh()->barangay);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_cross_barangay_workflow_ownership_privacy_history_calendar_and_notifications(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);
        $owner = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $home = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $facility = $this->resource();
        $this->actingAs($user)->post(route('reservations.store', $facility->slug), $this->booking())->assertSessionHasNoErrors()->assertRedirect();
        $reservation = Reservation::sole();
        $this->assertSame('Taft', $reservation->barangay);
        $this->assertSame($user->id, $reservation->user_id);
        $this->assertSame('Washington', $user->fresh()->barangay);
        $this->assertDatabaseCount('users', 3);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(0, $home->notifications()->count());
        $this->get(route('reservations.index'))->assertSee('Taft Court')->assertSee('Managing Barangay: Taft');
        $this->actingAs($home)->get(route('reservations.index'))->assertDontSee('Taft Court');
        foreach (['accept', 'reject'] as $action) {
            $this->post(route('reservations.'.$action, $reservation))->assertForbidden();
        }
        $this->patch(route('reservations.payment', $reservation), ['total_payment' => 100])->assertForbidden();
        $this->actingAs($owner)->get(route('reservations.index'))->assertSee($user->email)->assertSee('Home Barangay')->assertSee('Washington');
        $this->get(route('residents.index'))->assertDontSee($user->email);
        $this->get(route('residents.show', $user))->assertNotFound();
        $this->post(route('reservations.accept', $reservation), ['total_payment' => 100, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->actingAs($user)->get(route('notifications.index'))->assertJsonPath('unread_count', 1);
        $this->get(route('dashboard', ['facility' => $facility->slug, 'date' => today()->addDay()->toDateString(), 'month' => today()->addDay()->format('Y-m')]))
            ->assertOk()->assertViewHas('schedule', fn ($schedule) => $schedule->first()['status'] === 'booked');
    }

    public function test_residents_only_is_visible_but_cannot_be_bypassed_for_facilities_or_equipment(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);
        foreach (['Facility', 'Equipment'] as $category) {
            $facility = $this->resource(['slug' => strtolower($category), 'category' => $category, 'reservation_access' => 'residents_only']);
            $this->actingAs($user)->get(route('facilities.show', $facility->slug))->assertOk()->assertSee('Residents Only')->assertDontSee('Submit Reservation');
            $this->get(route('dashboard', ['facility' => $facility->slug]))->assertOk()->assertSee('Residents Only');
            $this->post(route('reservations.store', $facility->slug), $this->booking())->assertForbidden();
        }
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_same_barangay_can_reserve_restricted_resources_and_cross_barangay_can_reserve_open_equipment(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);
        foreach (['residents_only', 'all_registered_users'] as $access) {
            $facility = $this->resource(['slug' => $access, 'category' => 'Equipment', 'barangay' => $access === 'residents_only' ? 'Washington' : 'Taft', 'reservation_access' => $access]);
            $this->actingAs($user)->post(route('reservations.store', $facility->slug), $this->booking())->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->get(route('reservations.index'))->assertViewHas('reservations', fn ($rows) => $rows->count() === 2);
    }

    public function test_admin_access_setting_is_validated_and_scoped(): void
    {
        $facility = $this->resource(['reservation_access' => 'residents_only']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $data = $facility->only(['name', 'category', 'description', 'capacity', 'location', 'status']);
        $this->actingAs($admin)->patch(route('facilities.update', $facility->slug), [...$data, 'reservation_access' => 'invalid'])->assertSessionHasErrors('reservation_access');
        $this->patch(route('facilities.update', $facility->slug), [...$data, 'reservation_access' => 'all_registered_users'])->assertSessionHasNoErrors();
        $this->assertSame('all_registered_users', $facility->fresh()->reservation_access);
        $other = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($other)->patch(route('facilities.update', $facility->slug), $data)->assertNotFound();
    }

    public function test_resource_ownership_overrides_stale_snapshot_and_super_admin_keeps_access(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);
        $facility = $this->resource();
        $this->actingAs($user)->post(route('reservations.store', $facility->slug), $this->booking())->assertSessionHasNoErrors();
        $reservation = Reservation::sole();
        $reservation->update(['barangay' => 'Washington']);
        $home = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($home)->get(route('reservations.index'))->assertDontSee('Taft Court');
        $this->post(route('reservations.reject', $reservation))->assertForbidden();
        $owner = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($owner)->get(route('reservations.index'))->assertSee('Taft Court');
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->get(route('reservations.index'))->assertSee('Taft Court');
        $this->post(route('reservations.reject', $reservation))->assertRedirect();
        $this->assertSame('rejected', $reservation->fresh()->status);
    }
}
