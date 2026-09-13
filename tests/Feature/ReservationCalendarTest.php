<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_the_reservation_calendar_after_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Reservation Calendar');
        $response->assertSee('Reserve Selected Time');
    }

    public function test_reserved_time_is_not_selectable_on_the_schedule(): void
    {
        $user = User::factory()->create();

        $this->reservation([
            'reservation_date' => '2026-09-20',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-20',
        ]));

        $response->assertOk();
        $response->assertSee('9:00 AM - 10:00 AM');
        $response->assertSee('Reserved');
    }

    public function test_overlapping_reservation_is_rejected_before_saving(): void
    {
        $user = User::factory()->create();

        $this->reservation([
            'reservation_date' => now()->addDays(4)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->from(route('dashboard'))->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => now()->addDays(4)->toDateString(),
            'start_time' => '11:00',
            'end_time' => '12:00',
            'purpose' => 'Training',
            'attendees' => 20,
        ]);

        $response->assertSessionHasErrors('start_time');
        $this->assertSame(1, Reservation::count());
    }

    public function test_same_time_can_be_reserved_for_a_different_facility(): void
    {
        $user = User::factory()->create();
        $date = now()->addDays(5)->toDateString();

        $this->reservation([
            'facility_slug' => 'multi-purpose-court',
            'facility_name' => 'Multi-Purpose Court',
            'reservation_date' => $date,
            'start_time' => '14:00',
            'end_time' => '15:00',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->post(route('reservations.store', 'conference-room-a'), [
            'reservation_date' => $date,
            'start_time' => '14:00',
            'end_time' => '15:00',
            'purpose' => 'Committee meeting',
            'attendees' => 10,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Reservation::count());
    }

    public function test_declined_cancelled_and_rejected_reservations_do_not_block_availability(): void
    {
        $user = User::factory()->create();
        $date = now()->addDays(6)->toDateString();

        foreach (['declined', 'cancelled', 'rejected'] as $status) {
            $this->reservation([
                'reservation_date' => $date,
                'start_time' => '08:00',
                'end_time' => '09:00',
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($user)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Practice',
            'attendees' => 20,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(4, Reservation::count());
    }

    public function test_past_dates_cannot_be_reserved(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => now()->subDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Late request',
            'attendees' => 10,
        ]);

        $response->assertSessionHasErrors('reservation_date');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_admin_schedule_lists_reservations_with_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $resident = User::factory()->create(['name' => 'Maria Resident']);

        $this->reservation([
            'user_id' => $resident->id,
            'reservation_date' => '2026-09-20',
            'start_time' => '13:00',
            'end_time' => '14:00',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'admin_date' => '2026-09-20',
            'admin_status' => 'approved',
        ]));

        $response->assertOk();
        $response->assertSee('Admin Schedule');
        $response->assertSee('Maria Resident');
        $response->assertSee('Multi-Purpose Court');
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'facility_slug' => 'multi-purpose-court',
            'facility_name' => 'Multi-Purpose Court',
            'category' => 'Facility',
            'location' => 'Barangay Washington',
            'reservation_date' => now()->addDays(3)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Community activity',
            'attendees' => 25,
            'status' => 'pending',
        ], $overrides));
    }
}
