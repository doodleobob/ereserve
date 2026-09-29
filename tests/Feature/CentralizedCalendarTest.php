<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationAvailability;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CentralizedCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));
    }

    private function resource(string $barangay = 'Taft', string $category = 'Facility'): Facility
    {
        return Facility::create([
            'barangay' => $barangay, 'slug' => strtolower($barangay.'-'.$category),
            'name' => $barangay.' '.$category, 'category' => $category, 'description' => 'Shared resource',
            'capacity' => 50, 'location' => 'Town center', 'status' => 'Available',
            'reservation_access' => 'all_registered_users', 'hourly_rate' => 100,
        ]);
    }

    private function submit(User $user, Facility $facility): Reservation
    {
        $this->actingAs($user)->post(route('reservations.store', $facility->slug), [
            'reservation_date' => '2026-10-10', 'start_time' => '17:00', 'end_time' => '19:00',
            'purpose' => 'Private purpose text', 'attendees' => 10,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Reservation::latest('id')->firstOrFail();
    }

    private function calendarUrl(Facility $facility): string
    {
        return route('calendar', ['facility' => $facility->slug, 'month' => '2026-10', 'date' => '2026-10-10']);
    }

    public function test_accepted_cross_barangay_availability_is_identical_for_every_viewer_and_private(): void
    {
        $booker = User::factory()->create(['barangay' => 'Washington', 'name' => 'Private Booker', 'email' => 'private@example.test', 'phone_number' => '09171234567']);
        $facility = $this->resource();
        $reservation = $this->submit($booker, $facility);
        $owner = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($owner)->post(route('reservations.accept', $reservation), ['total_payment' => 200, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        // A legacy snapshot must not override the linked resource's owner.
        $reservation->update(['barangay' => 'Washington']);
        $expected = null;
        foreach ([['user', 'Washington'], ['user', 'Taft'], ['user', 'San Juan'], ['admin', 'Taft'], ['admin', 'Washington'], ['super_admin', 'San Juan']] as [$role, $barangay]) {
            $viewer = User::factory()->create(compact('role', 'barangay'));
            $response = $this->actingAs($viewer)->get($this->calendarUrl($facility))->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('October 10, 2026: Partially Booked')->assertSee('5:00 PM - 7:00 PM')
                ->assertDontSee('Private Booker')->assertDontSee('private@example.test')
                ->assertDontSee('+639171234567')->assertDontSee('Private purpose text');
            $actual = [$response->viewData('calendarDays')->toArray(), $response->viewData('schedule')->toArray(), $response->viewData('calendarEvents')->toArray()];
            $expected ??= $actual;
            $this->assertEquals($expected, $actual);
            $this->assertSame($barangay, $viewer->fresh()->barangay);
        }
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame('Washington', $booker->fresh()->barangay);
    }

    public function test_barangay_filters_list_owned_resources_without_loading_unselected_calendars(): void
    {
        $booker = User::factory()->create(['barangay' => 'Washington']);
        $taft = $this->resource();
        $home = $this->resource('Washington');
        $equipment = $this->resource('Taft', 'Equipment');
        foreach ([$taft, $home, $equipment] as $resource) {
            $this->submit($booker, $resource)->update(['status' => 'accepted']);
        }
        $this->get(route('calendar', ['barangay' => 'Taft', 'type' => 'facility', 'month' => '2026-10']))->assertOk()
            ->assertViewHas('facilities', fn ($rows) => $rows->count() === 1 && $rows->every(fn ($row) => $row['barangay'] === 'Taft' && $row['category'] === 'Facility'))
            ->assertViewHas('selectedFacility', null)->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty());
        $this->get(route('calendar', ['barangay' => 'Washington', 'type' => 'facility', 'month' => '2026-10']))->assertOk()
            ->assertViewHas('facilities', fn ($rows) => $rows->count() === 1 && $rows->first()['id'] === $home->id);
        $this->get(route('calendar', ['barangay' => 'all', 'month' => '2026-10']))
            ->assertRedirect(route('calendar', ['month' => '2026-10']));
        $this->get(route('calendar', ['barangay' => 'Washington', 'facility' => $taft->slug]))->assertOk()
            ->assertViewHas('selectedFacility', null);
        $this->assertDatabaseCount('reservations', 3);
    }

    public function test_refresh_preserves_accepted_only_status_rules_for_same_and_cross_barangay(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);
        foreach (['Washington', 'Taft'] as $barangay) {
            $resource = $this->resource($barangay);
            $reservation = $this->submit($user, $resource);
            $this->get($this->calendarUrl($resource))->assertSee('October 10, 2026: Available')->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty());
            $reservation->update(['status' => 'accepted']);
            $this->get($this->calendarUrl($resource))->assertSee('October 10, 2026: Partially Booked')->assertViewHas('calendarEvents', fn ($events) => $events->count() === 1);
            $reservation->update(['status' => 'rejected']);
            $this->get($this->calendarUrl($resource))->assertSee('October 10, 2026: Available')->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty());
        }
    }

    public function test_shared_calendar_does_not_grant_admin_management_or_resident_booking_access(): void
    {
        $booker = User::factory()->create(['barangay' => 'Washington']);
        $facility = $this->resource();
        $reservation = $this->submit($booker, $facility);
        $facility->update(['reservation_access' => 'residents_only']);
        $this->get($this->calendarUrl($facility))->assertOk()->assertSee('Residents Only')->assertDontSee('Submit Reservation');
        foreach (['Washington', 'San Juan'] as $barangay) {
            $admin = User::factory()->create(['role' => 'admin', 'barangay' => $barangay]);
            $this->actingAs($admin)->get($this->calendarUrl($facility))->assertOk();
            $this->post(route('reservations.accept', $reservation), ['total_payment' => 200, 'payment_confirmed' => 1])->assertForbidden();
            $this->post(route('reservations.reject', $reservation))->assertForbidden();
            $this->patch(route('reservations.payment', $reservation), ['total_payment' => 200])->assertForbidden();
            $this->get(route('residents.show', $booker))->assertStatus($barangay === 'Washington' ? 200 : 404);
        }
        $owner = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($owner)->post(route('reservations.accept', $reservation), ['total_payment' => 200, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->patch(route('reservations.payment', $reservation), ['total_payment' => 250])->assertSessionHasNoErrors();
        $this->assertSame('250.00', $reservation->fresh()->total_payment);
    }

    public function test_calendar_requires_one_valid_resource_for_every_role_and_preserves_navigation(): void
    {
        $facility = $this->resource();
        $equipment = $this->resource('Taft', 'Equipment');
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $viewer = User::factory()->create(['role' => $role, 'barangay' => 'Washington']);
            $this->actingAs($viewer)->get(route('calendar', ['barangay' => 'Taft', 'facility' => '']))->assertOk()
                ->assertSee('Select Resource Type First')->assertDontSee('All Barangays')->assertDontSee('View Calendar')
                ->assertDontSee('All Facilities &amp; Equipment', false)->assertDontSee('class="calendar-grid"', false)
                ->assertViewHas('selectedFacility', null);
            $this->get(route('calendar', ['barangay' => 'Taft', 'facility' => 'missing']))->assertOk()->assertViewHas('selectedFacility', null);
            foreach ([$facility, $equipment] as $resource) {
                $response = $this->get($this->calendarUrl($resource))->assertOk()
                    ->assertViewHas('selectedFacility', fn ($selected) => $selected['id'] === $resource->id);
                $this->assertSame(1, substr_count($response->getContent(), 'class="calendar-grid"'));
                $response->assertSee(route('calendar', ['barangay' => 'Taft', 'type' => strtolower($resource->category), 'facility' => $resource->slug, 'month' => '2026-11']));
            }
            $this->get(route('calendar', ['barangay' => 'Taft', 'facility' => 'all']))
                ->assertRedirect(route('calendar', ['barangay' => 'Taft']));
            $this->get(route('calendar', ['barangay' => 'Taft', 'resource' => 'all']))
                ->assertRedirect(route('calendar', ['barangay' => 'Taft']));
        }
        $this->travelTo(Carbon::parse('2026-10-31'));
        $this->get(route('calendar', ['facility' => $facility->slug, 'month' => '2027-02', 'date' => '2027-02-10']))
            ->assertOk()->assertViewHas('month', fn ($month) => $month->format('Y-m') === '2027-02');
    }

    public function test_progressive_filters_defaults_types_resets_and_empty_states(): void
    {
        $court = $this->resource('Taft');
        $equipment = $this->resource('Taft', 'Equipment');
        $home = $this->resource('Washington');
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'barangay' => 'Washington']);
            $this->actingAs($user)->get(route('calendar'))->assertOk()
                ->assertViewHas('selectedBarangay', 'Washington')->assertViewHas('selectedType', null)
                ->assertViewHas('facilities', fn ($rows) => $rows->isEmpty())->assertDontSee('All Barangays');
            foreach (['facility' => $court, 'equipment' => $equipment] as $type => $resource) {
                $this->get(route('calendar', ['barangay' => 'Taft', 'type' => $type]))->assertOk()
                    ->assertViewHas('facilities', fn ($rows) => $rows->count() === 1 && $rows->first()['id'] === $resource->id)
                    ->assertViewHas('selectedFacility', null);
                $this->get(route('calendar', ['barangay' => 'Taft', 'type' => $type, 'facility' => $resource->slug]))->assertOk()
                    ->assertViewHas('selectedFacility', fn ($selected) => $selected['id'] === $resource->id);
            }
            $this->get(route('calendar', ['barangay' => 'Taft', 'type' => 'equipment', 'facility' => $court->slug]))
                ->assertOk()->assertViewHas('selectedFacility', null);
            $this->get(route('calendar', ['barangay' => 'Taft', 'type' => 'facility', 'facility' => $home->slug]))
                ->assertOk()->assertViewHas('selectedFacility', null);
            $this->get(route('calendar', ['barangay' => 'Washington', 'type' => 'equipment']))
                ->assertOk()->assertSee('No equipment available')->assertSee('No equipment is currently available for this barangay.');
            $this->get(route('calendar', ['type' => 'invalid']))->assertSessionHasErrors('type');
            $this->assertSame('Washington', $user->fresh()->barangay);
        }
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => '']);
        $this->actingAs($super)->get(route('calendar'))->assertOk()->assertViewHas('selectedBarangay', 'Taft');
    }

    public function test_legacy_matching_and_empty_filters_remain_safe(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);
        $facility = $this->resource();
        $reservation = $this->submit($user, $facility);
        $reservation->update(['facility_id' => null, 'status' => 'accepted']);
        $this->get($this->calendarUrl($facility))->assertSee('October 10, 2026: Partially Booked');
        $reservation->update(['barangay' => 'Washington']);
        $this->assertCount(0, ReservationAvailability::acceptedReservations($facility->id, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-10')));
        $this->get(route('calendar', ['barangay' => 'Washington']))->assertOk()->assertSee('Select a facility or equipment to view its availability calendar.');
        $this->get(route('calendar', ['barangay' => 'Invalid']))->assertSessionHasErrors('barangay');
        $this->get(route('calendar', ['month' => 'invalid']))->assertSessionHasErrors('month');
    }
}
