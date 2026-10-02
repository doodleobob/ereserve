<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptedReservationEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $resident;

    private Facility $facility;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $this->facility = Facility::create(['barangay' => 'Taft', 'slug' => 'court', 'name' => 'Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
        $this->reservation = Reservation::create(['user_id' => $this->resident->id, 'facility_id' => $this->facility->id, 'barangay' => 'Taft', 'facility_slug' => 'court', 'facility_name' => 'Court', 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => today()->addDays(2)->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00', 'purpose' => 'Workshop', 'attendees' => 10, 'status' => 'accepted', 'hourly_rate_snapshot' => 100, 'total_payment' => 500]);
        $this->actingAs($this->admin);
    }

    private function schedule(array $overrides = []): array
    {
        return array_merge(['action' => 'reschedule', 'reservation_date' => $this->reservation->reservation_date, 'start_time' => '13:00', 'end_time' => '15:00'], $overrides);
    }

    private function edit(array $data)
    {
        return $this->patchJson(route('reservations.edit-accepted', $this->reservation), $data);
    }

    public function test_reschedule_excludes_itself_and_preserves_recorded_payment_and_history(): void
    {
        $this->edit($this->schedule())->assertOk();
        $this->edit($this->schedule(['reservation_date' => today()->addDays(3)->toDateString(), 'start_time' => '22:00', 'end_time' => '02:00', 'total_payment' => 999]))->assertOk();
        $r = $this->reservation->fresh();
        $this->assertSame('accepted', $r->status);
        $this->assertSame('500.00', $r->total_payment);
        $this->assertSame('100.00', $r->hourly_rate_snapshot);
        $this->assertSame(240, $r->durationMinutes());
        $this->assertCount(2, $r->change_history);
        $this->assertSame($this->admin->id, $r->change_history[1]['actor_id']);
        $this->assertSame('13:00', substr($r->change_history[1]['before']['start_time'], 0, 5));
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_validation_and_half_open_overnight_resource_scoped_conflicts(): void
    {
        foreach ([['reservation_date' => today()->subDay()->toDateString()], ['reservation_date' => 'bad'], ['start_time' => '25:00'], ['end_time' => '13:00'], ['start_time' => '']] as $invalid) {
            $this->edit($this->schedule($invalid))->assertUnprocessable();
        }
        $other = $this->reservation->replicate();
        $other->start_time = '23:00';
        $other->end_time = '02:00';
        $other->facility_id = null;
        $other->save();
        $nextDay = today()->addDays(3)->toDateString();
        $this->edit($this->schedule(['reservation_date' => $nextDay, 'start_time' => '01:00', 'end_time' => '03:00']))->assertUnprocessable();
        $this->edit($this->schedule(['reservation_date' => $nextDay, 'start_time' => '02:00', 'end_time' => '03:00']))->assertOk();
        $other->update(['barangay' => 'Washington']);
        $this->edit($this->schedule(['reservation_date' => $nextDay, 'start_time' => '01:00', 'end_time' => '03:00']))->assertOk();
        $other->update(['barangay' => 'Taft', 'status' => 'pending']);
        $this->edit($this->schedule(['reservation_date' => $nextDay, 'start_time' => '01:00', 'end_time' => '03:00']))->assertOk();
        $this->facility->update(['status' => 'Unavailable']);
        $this->edit($this->schedule())->assertUnprocessable();
        $this->assertSame('500.00', $this->reservation->fresh()->total_payment);
    }

    public function test_cancel_requires_confirmation_and_reason_and_preserves_payment_without_refund(): void
    {
        $cancel = ['action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'User Requested Cancellation', 'cancellation_notes' => 'Resident called.'];
        foreach ([['cancellation_confirmed' => 0], ['cancellation_reason' => ''], ['cancellation_reason' => 'invalid'], ['cancellation_notes' => str_repeat('a', 1001)]] as $invalid) {
            $this->edit(array_merge($cancel, $invalid))->assertUnprocessable();
        }
        $this->assertSame('accepted', $this->reservation->fresh()->status);
        $this->edit($cancel + ['total_payment' => 0])->assertOk()->assertJsonPath('message', 'Reservation cancelled successfully.');
        $r = $this->reservation->fresh();
        $this->assertSame('cancelled', $r->status);
        $this->assertSame('500.00', $r->total_payment);
        $this->assertSame('User Requested Cancellation', $r->cancellation_reason);
        $this->assertSame('Resident called.', $r->cancellation_notes);
        $this->assertNotNull($r->cancelled_at);
        $this->assertSame('accepted', $r->change_history[0]['before']['status']);
        $this->assertDatabaseCount('reservations', 1);
        $notice = $this->resident->notifications()->first()->data;
        $this->assertSame('Reservation Cancelled', $notice['title']);
        $this->assertSame('User Requested Cancellation', $notice['cancellation_reason']);
        $this->assertStringContainsString('Court', $notice['message']);
        $this->assertStringContainsString($r->period()->start->format('F j, Y'), $notice['message']);
        $this->assertSame('500.00', $notice['total_payment']);
        $this->edit($cancel)->assertUnprocessable();
        $this->assertSame(1, $this->resident->notifications()->count());
        $this->get(route('reservations.index'))->assertSee('Resident called.')->assertDontSee('id="edit-'.$r->id.'"', false);
    }

    public function test_authorization_status_guards_and_no_amount_edit_through_schedule_endpoint(): void
    {
        $this->edit(['total_payment' => 1])->assertUnprocessable();
        foreach ([$this->resident, User::factory()->create(['role' => 'admin', 'barangay' => 'Washington'])] as $actor) {
            $this->actingAs($actor);
            $this->edit($this->schedule())->assertForbidden();
            $this->edit(['action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'Other'])->assertForbidden();
        }
        $this->actingAs($this->admin);
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->reservation->update(['status' => $status]);
            $this->edit($this->schedule())->assertUnprocessable();
        }
        $this->assertNull($this->reservation->fresh()->change_history);
    }
}
