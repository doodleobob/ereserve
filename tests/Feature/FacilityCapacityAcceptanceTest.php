<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationAvailability;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FacilityCapacityAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $this->facility = Facility::create([
            'barangay' => 'Taft', 'slug' => 'court', 'name' => 'Court', 'category' => 'Facility',
            'description' => 'Court', 'capacity' => 200, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100,
        ]);
    }

    private function booking(array $attributes = []): Reservation
    {
        return Reservation::create(array_replace([
            'user_id' => $this->resident->id, 'facility_id' => $this->facility->id, 'barangay' => 'Taft',
            'facility_slug' => 'court', 'facility_name' => 'Court', 'category' => 'Facility', 'location' => 'Taft',
            'reservation_date' => '2026-10-20', 'start_time' => '08:15', 'end_time' => '10:20',
            'purpose' => 'Meeting', 'attendees' => 150, 'status' => 'pending', 'hourly_rate_snapshot' => 100,
        ], $attributes));
    }

    private function accept(Reservation $reservation)
    {
        return $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), [
            'total_payment' => '208.33', 'payment_confirmed' => 1,
        ]);
    }

    public function test_capacity_validation_reuses_attendees_and_returns_a_clear_error(): void
    {
        $data = ['reservation_date' => '2026-10-20', 'start_time' => '08:15', 'end_time' => '10:20', 'purpose' => 'Meeting'];
        foreach ([0, -1, 1.5, 201] as $attendees) {
            $this->actingAs($this->resident)->postJson(route('reservations.store', 'court'), $data + ['attendees' => $attendees])
                ->assertUnprocessable()->assertJsonValidationErrors('attendees');
        }
        $this->postJson(route('reservations.store', 'court'), $data + ['attendees' => 201])
            ->assertJsonPath('errors.attendees.0', "The number of attendees exceeds this facility's maximum capacity of 200 people.");
        $this->postJson(route('reservations.store', 'court'), $data + ['attendees' => 200])->assertCreated();
    }

    public function test_only_one_overlapping_request_can_be_accepted_regardless_of_attendees(): void
    {
        $first = $this->booking();
        $second = $this->booking(['attendees' => 1, 'start_time' => '09:01', 'end_time' => '11:00']);
        $date = $first->period()->start->copy()->startOfDay();
        $this->assertCount(0, ReservationAvailability::acceptedReservations($this->facility->id, $date, $date));
        $this->accept($first)->assertOk();
        $this->accept($second)->assertUnprocessable()->assertJsonValidationErrors('reservation');
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertNull($second->fresh()->total_payment);
        $this->assertNull($second->fresh()->payment);
        $this->assertSame('paid', $first->fresh()->payment->payment_status);
        $this->assertCount(1, ReservationAvailability::acceptedReservations($this->facility->id, $date, $date));
        $this->assertSame('booked', ReservationAvailability::daySchedule($this->facility->id, $date)->first()['status']);
    }

    public function test_adjacent_custom_minute_and_overnight_bookings_are_allowed_but_overlaps_are_not(): void
    {
        $this->accept($this->booking(['start_time' => '23:15', 'end_time' => '01:20']))->assertOk();
        $this->accept($this->booking(['reservation_date' => '2026-10-21', 'start_time' => '01:19', 'end_time' => '02:00']))
            ->assertUnprocessable();
        $this->accept($this->booking(['reservation_date' => '2026-10-21', 'start_time' => '01:20', 'end_time' => '02:00']))
            ->assertOk();
        $this->accept($this->booking(['start_time' => '22:00', 'end_time' => '23:15']))->assertOk();
    }

    public function test_cancellation_releases_time_and_retains_the_refund_workflow(): void
    {
        $first = $this->booking();
        $second = $this->booking();
        $this->accept($first)->assertOk();
        $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $first), [
            'action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'User Requested Cancellation',
        ])->assertOk();
        $this->accept($second)->assertOk();
        $this->assertSame('208.33', $first->fresh()->total_payment);
        $this->assertSame('refund_pending', $first->fresh()->payment->payment_status);
    }

    public function test_acceptance_rechecks_current_capacity_without_changing_pending_request(): void
    {
        $reservation = $this->booking();
        $this->facility->update(['capacity' => 100]);
        $this->accept($reservation)->assertUnprocessable()->assertJsonValidationErrors('attendees');
        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->payment);
    }

    public function test_legacy_reservations_are_included_in_the_same_resource_lock_and_overlap_check(): void
    {
        $legacy = $this->booking(['facility_id' => null]);
        $this->accept($legacy)->assertOk();
        $this->assertSame($this->facility->id, $legacy->fresh()->facility_id);
        $this->accept($this->booking())->assertUnprocessable();
    }
}
