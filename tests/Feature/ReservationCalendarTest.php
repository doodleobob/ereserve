<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        foreach ($this->testFacilities() as $facility) {
            Facility::create($facility);
        }
    }

    public function test_dashboard_shows_the_reservation_calendar_after_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Reservation Calendar');
        $response->assertSee('Reserve Selected Time');
    }

    public function test_future_accepted_time_is_booked_and_selectable_on_the_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 08:00:00'));
        $user = User::factory()->create();

        $this->reservation([
            'reservation_date' => '2026-09-25',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-25',
        ]));

        $response->assertOk();
        $response->assertSee('9:00 AM - 10:00 AM');
        $response->assertSee('Booked');
        $response->assertSee('schedule-slot-booked', false);
        $response->assertSee('aria-label="September 25, 2026: Partially Booked"', false);
        $response->assertDontSee('aria-label="September 25, 2026: In Use"', false);
        $response->assertSee(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-25',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]));
    }

    public function test_accepted_time_outside_the_default_grid_is_partial_booked_and_selectable(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 08:00:00'));
        $userA = User::factory()->create(['barangay' => 'Washington']);
        $userB = User::factory()->create(['barangay' => 'Washington']);

        $this->reservation([
            'user_id' => $userA->id,
            'reservation_date' => '2026-09-24',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($userB)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-24',
        ]));

        $response->assertOk();
        $response->assertSee('aria-label="September 24, 2026: Partially Booked"', false);
        $response->assertSee('6:00 PM - 7:00 PM');
        $response->assertSee('schedule-slot-booked', false);
        $response->assertSee(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-24',
            'start_time' => '18:00',
            'end_time' => '19:00',
        ]));

        $selectedResponse = $this->actingAs($userB)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-24',
            'start_time' => '18:00',
            'end_time' => '19:00',
        ]));

        $selectedResponse->assertOk();
        $selectedResponse->assertSee('Submit Reservation');

        $this->actingAs($userB)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => '2026-09-24',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'purpose' => 'Overlapping evening request',
            'attendees' => 10,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reservations', [
            'user_id' => $userB->id,
            'barangay' => 'Washington',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'status' => 'pending',
        ]);
    }

    public function test_accepted_reservation_is_shared_with_other_users_in_the_same_barangay(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 08:00:00'));
        $userA = User::factory()->create(['barangay' => 'Washington']);
        $userB = User::factory()->create(['barangay' => 'Washington']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $date = now()->addDays(7)->toDateString();

        $this->actingAs($userA)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'purpose' => 'Community program',
            'attendees' => 40,
        ])->assertSessionHasNoErrors();

        $reservation = Reservation::query()
            ->where('user_id', $userA->id)
            ->where('facility_slug', 'multi-purpose-court')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('reservations.accept', $reservation))
            ->assertRedirect(route('reservations.index'));

        $this->assertSame('accepted', $reservation->fresh()->status);

        $response = $this->actingAs($userB)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => now()->addDays(7)->format('Y-m'),
            'date' => $date,
        ]));

        $response->assertOk();
        $response->assertSee('Booked');
        $response->assertSee('schedule-slot-booked', false);
        $response->assertSee('Partially Booked');
        $response->assertSee('8:00 AM - 12:00 PM');
        $response->assertDontSee($userA->name);

        $this->actingAs($userB)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => $date,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'purpose' => 'Overlapping request',
            'attendees' => 20,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reservations', [
            'user_id' => $userB->id,
            'facility_id' => $reservation->facility_id,
            'barangay' => 'Washington',
            'status' => 'pending',
        ]);
    }

    public function test_shared_calendar_is_scoped_by_barangay_and_selected_facility(): void
    {
        $washingtonUser = User::factory()->create(['barangay' => 'Washington']);
        $alegriaUser = User::factory()->create(['barangay' => 'Alegria']);
        $date = now()->addDays(8)->toDateString();

        $this->reservation([
            'user_id' => $washingtonUser->id,
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'accepted',
        ]);

        $otherFacilityResponse = $this->actingAs($washingtonUser)->get(route('dashboard', [
            'facility' => 'conference-room-a',
            'month' => now()->addDays(8)->format('Y-m'),
            'date' => $date,
        ]));

        $otherFacilityResponse->assertOk();
        $otherFacilityResponse->assertDontSee('schedule-slot-booked', false);

        $otherBarangayResponse = $this->actingAs($alegriaUser)->get(route('dashboard', [
            'facility' => 'alegria-covered-court',
            'month' => now()->addDays(8)->format('Y-m'),
            'date' => $date,
        ]));

        $otherBarangayResponse->assertOk();
        $otherBarangayResponse->assertDontSee('schedule-slot-booked', false);
        $otherBarangayResponse->assertDontSee('8:00 AM - 12:00 PM');
    }

    public function test_legacy_reservation_without_facility_id_is_shared_and_repaired_when_accepted(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 08:00:00'));
        $userA = User::factory()->create(['barangay' => 'Washington']);
        $userB = User::factory()->create(['barangay' => 'Washington']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $facility = Facility::query()->where('slug', 'multi-purpose-court')->firstOrFail();
        $date = now()->addDays(9)->toDateString();

        $reservation = $this->reservation([
            'user_id' => $userA->id,
            'facility_id' => null,
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->post(route('reservations.accept', $reservation));

        $this->assertSame($facility->id, $reservation->fresh()->facility_id);

        $response = $this->actingAs($userB)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => now()->addDays(9)->format('Y-m'),
            'date' => $date,
        ]));

        $response->assertOk();
        $response->assertSee('Booked');
        $response->assertSee('8:00 AM - 12:00 PM');
    }

    public function test_accepted_time_changes_from_booked_to_in_use_using_the_application_clock(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);

        $this->reservation([
            'reservation_date' => '2026-09-25',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => 'accepted',
        ]);

        $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));

        $beforeResponse = $this->actingAs($user)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-25',
        ]));

        $beforeResponse->assertOk();
        $beforeResponse->assertSee('Booked');
        $beforeResponse->assertDontSee('aria-label="September 25, 2026: In Use"', false);

        $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));

        $duringResponse = $this->actingAs($user)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-25',
        ]));

        $duringResponse->assertOk();
        $duringResponse->assertSee('In Use');
        $duringResponse->assertSee('schedule-slot-in-use', false);
        $duringResponse->assertSee('aria-label="September 25, 2026: Partially Booked"', false);

        $this->travelTo(Carbon::parse('2026-09-25 10:30:00'));

        $afterResponse = $this->actingAs($user)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-25',
        ]));

        $afterResponse->assertOk();
        $afterResponse->assertSee('Booked');
        $afterResponse->assertSee('schedule-slot-booked', false);
        $afterResponse->assertDontSee('schedule-slot-in-use', false);
    }

    public function test_month_marks_a_day_fully_booked_only_when_all_slots_are_covered(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 08:00:00'));
        $user = User::factory()->create(['barangay' => 'Washington']);

        $this->reservation([
            'reservation_date' => '2026-09-26',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'accepted',
        ]);
        $this->reservation([
            'reservation_date' => '2026-09-26',
            'start_time' => '13:00',
            'end_time' => '17:00',
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-26',
        ]));

        $response->assertOk();
        $response->assertSee('aria-label="September 26, 2026: Fully Booked"', false);
    }

    public function test_pending_and_rejected_reservations_do_not_change_shared_availability(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 08:00:00'));
        $user = User::factory()->create(['barangay' => 'Washington']);

        foreach (['pending', 'rejected'] as $status) {
            $this->reservation([
                'reservation_date' => '2026-09-27',
                'start_time' => $status === 'pending' ? '08:00' : '09:00',
                'end_time' => $status === 'pending' ? '09:00' : '10:00',
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'month' => '2026-09',
            'date' => '2026-09-27',
        ]));

        $response->assertOk();
        $response->assertSee('aria-label="September 27, 2026: Available"', false);
        $response->assertDontSee('schedule-slot-booked', false);
        $response->assertDontSee('schedule-slot-in-use', false);
    }

    public function test_overlapping_reservation_can_be_requested_before_admin_acceptance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->reservation([
            'reservation_date' => now()->addDays(4)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'status' => 'accepted',
        ]);
        $reservationCount = Reservation::count();

        $response = $this->actingAs($otherUser)->from(route('dashboard'))->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => now()->addDays(4)->toDateString(),
            'start_time' => '11:00',
            'end_time' => '13:00',
            'purpose' => 'Training',
            'attendees' => 20,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($reservationCount + 1, Reservation::count());
    }

    public function test_user_cannot_create_duplicate_active_reservation_for_same_facility(): void
    {
        $user = User::factory()->create();

        $this->reservation([
            'user_id' => $user->id,
            'reservation_date' => now()->addDays(4)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'status' => 'pending',
        ]);
        $reservationCount = Reservation::count();

        $response = $this->actingAs($user)->from(route('dashboard'))->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => now()->addDays(5)->toDateString(),
            'start_time' => '13:00',
            'end_time' => '14:00',
            'purpose' => 'Training',
            'attendees' => 20,
        ]);

        $response->assertSessionHasErrors('reservation');
        $this->assertSame($reservationCount, Reservation::count());
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
            'status' => 'accepted',
        ]);
        $reservationCount = Reservation::count();

        $response = $this->actingAs($user)->post(route('reservations.store', 'conference-room-a'), [
            'reservation_date' => $date,
            'start_time' => '14:00',
            'end_time' => '15:00',
            'purpose' => 'Committee meeting',
            'attendees' => 10,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($reservationCount + 1, Reservation::count());
    }

    public function test_rejected_reservation_allows_a_new_request_for_same_facility(): void
    {
        $user = User::factory()->create();
        $date = now()->addDays(6)->toDateString();

        $this->reservation([
            'user_id' => $user->id,
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'status' => 'rejected',
        ]);
        $reservationCount = Reservation::count();

        $response = $this->actingAs($user)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Practice',
            'attendees' => 20,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($reservationCount + 1, Reservation::count());
    }

    public function test_past_dates_cannot_be_reserved(): void
    {
        $user = User::factory()->create();
        $reservationCount = Reservation::count();

        $response = $this->actingAs($user)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => now()->subDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Late request',
            'attendees' => 10,
        ]);

        $response->assertSessionHasErrors('reservation_date');
        $this->assertSame($reservationCount, Reservation::count());
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
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard', [
            'facility' => 'multi-purpose-court',
            'admin_date' => '2026-09-20',
            'admin_status' => 'accepted',
        ]));

        $response->assertOk();
        $response->assertSee('Admin Schedule');
        $response->assertSee('Maria Resident');
        $response->assertSee('Multi-Purpose Court');
    }

    private function reservation(array $overrides = []): Reservation
    {
        $facilitySlug = $overrides['facility_slug'] ?? 'multi-purpose-court';
        $barangay = $overrides['barangay'] ?? 'Washington';
        $facility = Facility::query()
            ->where('barangay', $barangay)
            ->where('slug', $facilitySlug)
            ->first();

        return Reservation::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'barangay' => $barangay,
            'facility_id' => $facility?->id,
            'facility_slug' => $facilitySlug,
            'facility_name' => $facility?->name ?? 'Multi-Purpose Court',
            'category' => $facility?->category ?? 'Facility',
            'location' => $facility?->location ?? 'Barangay Washington',
            'reservation_date' => now()->addDays(3)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Community activity',
            'attendees' => 25,
            'status' => 'pending',
        ], $overrides));
    }

    private function testFacilities(): array
    {
        return [
            [
                'barangay' => 'Washington',
                'slug' => 'multi-purpose-court',
                'name' => 'Multi-Purpose Court',
                'description' => 'Outdoor covered court for sports and recreational activities.',
                'list_description' => 'Outdoor covered court for sports and recreational activities.',
                'category' => 'Facility',
                'capacity' => 200,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
            [
                'barangay' => 'Washington',
                'slug' => 'conference-room-a',
                'name' => 'Conference Room A',
                'description' => 'Small conference room ideal for meetings and training sessions.',
                'list_description' => 'Small conference room ideal for meetings and training sessions.',
                'category' => 'Facility',
                'capacity' => 30,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
            [
                'barangay' => 'Alegria',
                'slug' => 'alegria-covered-court',
                'name' => 'Alegria Covered Court',
                'description' => 'Covered court for Barangay Alegria community activities.',
                'list_description' => 'Covered court for Barangay Alegria community activities.',
                'category' => 'Facility',
                'capacity' => 150,
                'location' => 'Barangay Alegria',
                'status' => 'Available',
            ],
        ];
    }
}
