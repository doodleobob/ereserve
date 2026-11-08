<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Support\AdminPermission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SuperAdminReservationOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $taftAdmin;

    private User $washingtonAdmin;

    private User $resident;

    private array $facilities = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->taftAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->washingtonAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
        foreach (['Taft', 'Washington'] as $barangay) {
            $slug = strtolower($barangay).'-hall';
            $this->facilities[$barangay] = Facility::create([
                'barangay' => $barangay, 'slug' => $slug, 'name' => $barangay.' Hall', 'category' => 'Facility',
                'description' => 'Community hall', 'capacity' => 50, 'location' => $barangay,
                'status' => 'Available', 'hourly_rate' => 100,
            ]);
        }
    }

    private function reservation(string $barangay, string $status = 'pending'): Reservation
    {
        $facility = $this->facilities[$barangay];

        return Reservation::create([
            'user_id' => $this->resident->id, 'barangay' => $barangay, 'facility_id' => $facility->id,
            'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => 'Facility',
            'location' => $barangay, 'reservation_date' => today()->addDays(8)->toDateString(),
            'start_time' => '13:00', 'end_time' => '15:00', 'purpose' => 'Community meeting',
            'attendees' => 10, 'status' => $status, 'hourly_rate_snapshot' => 100,
            'total_payment' => $status === 'accepted' ? 200 : null,
        ]);
    }

    public function test_super_admin_views_and_accepts_pending_reservations_across_barangays(): void
    {
        $taft = $this->reservation('Taft');
        $washington = $this->reservation('Washington');

        $this->actingAs($this->super)->get(route('reservations.index'))->assertOk()
            ->assertSee('Taft Hall')->assertSee('Washington Hall')
            ->assertSee('Managing Barangay')->assertSee('Washington', false)
            ->assertSee('data-confirm-payment', false)->assertSee('id="reject-'.$washington->id.'"', false);
        foreach ([$taft, $washington] as $reservation) {
            $this->postJson(route('reservations.accept', $reservation), [
                'total_payment' => '200.00', 'payment_confirmed' => 1, 'barangay' => 'Taft',
            ])->assertOk();
            $this->assertSame('accepted', $reservation->fresh()->status);
            $this->assertSame('200.00', $reservation->fresh()->payment->amount);
        }
        Notification::assertSentTo($this->resident, ReservationActivity::class);
    }

    public function test_super_admin_rejects_pending_reservations_across_barangays(): void
    {
        foreach (['Taft', 'Washington'] as $barangay) {
            $reservation = $this->reservation($barangay);
            $this->actingAs($this->super)->postJson(route('reservations.reject', $reservation), [
                'rejection_confirmed' => 1, 'barangay' => 'Taft',
            ])->assertOk();
            $this->assertSame('rejected', $reservation->fresh()->status);
        }
    }

    public function test_super_admin_reschedules_and_cancels_accepted_reservations_across_barangays(): void
    {
        foreach (['Taft', 'Washington'] as $barangay) {
            $reservation = $this->reservation($barangay, 'accepted');
            $this->actingAs($this->super)->patchJson(route('reservations.edit-accepted', $reservation), [
                'action' => 'reschedule', 'reservation_date' => today()->addDays(9)->toDateString(),
                'start_time' => '14:00', 'end_time' => '16:00', 'barangay' => 'Taft',
            ])->assertOk();
            $rescheduled = $reservation->fresh();
            $this->assertSame('accepted', $rescheduled->status);
            $this->assertSame('14:00', substr($rescheduled->start_time, 0, 5));
            $this->assertSame($this->super->id, $rescheduled->change_history[0]['actor_id']);

            $this->patchJson(route('reservations.edit-accepted', $reservation), [
                'action' => 'cancel', 'cancellation_confirmed' => 1,
                'cancellation_reason' => 'Other', 'cancellation_notes' => 'Super Admin review',
            ])->assertOk();
            $this->assertSame('cancelled', $reservation->fresh()->status);
            $this->assertSame('200.00', $reservation->fresh()->total_payment);
        }
    }

    public function test_barangay_admin_is_scoped_and_restrictions_disable_only_the_selected_action(): void
    {
        $taft = $this->reservation('Taft');
        $washington = $this->reservation('Washington');

        $this->actingAs($this->taftAdmin)->get(route('reservations.index'))->assertOk()
            ->assertSee('Taft Hall')->assertDontSee('Washington Hall');
        $this->postJson(route('reservations.accept', $taft), ['total_payment' => 200, 'payment_confirmed' => 1])->assertOk();
        $this->postJson(route('reservations.reject', $washington), ['rejection_confirmed' => 1])->assertForbidden();
        $this->postJson(route('reservations.accept', $washington), ['total_payment' => 200, 'payment_confirmed' => 1, 'barangay' => 'Taft'])->assertForbidden();

        $restricted = $this->reservation('Taft');
        $this->taftAdmin->restrictedPermissions()->create(['permission' => AdminPermission::RESERVATIONS_ACCEPT->value]);
        $this->postJson(route('reservations.accept', $restricted), ['total_payment' => 200, 'payment_confirmed' => 1])->assertForbidden();
        $this->postJson(route('reservations.reject', $restricted), ['rejection_confirmed' => 1])->assertForbidden();
        $this->taftAdmin->restrictedPermissions()->where('permission', AdminPermission::RESERVATIONS_ACCEPT->value)->delete();
        $this->taftAdmin->restrictedPermissions()->create(['permission' => AdminPermission::RESERVATIONS_MANAGE->value]);
        $this->postJson(route('reservations.reject', $restricted), ['rejection_confirmed' => 1])->assertOk();
        $this->assertSame('pending', $washington->fresh()->status);
    }

    public function test_residents_and_inactive_administrators_cannot_use_admin_reservation_actions(): void
    {
        $pending = $this->reservation('Washington');
        $accepted = $this->reservation('Washington', 'accepted');

        $this->actingAs($this->resident)->postJson(route('reservations.accept', $pending), [])->assertForbidden();
        $this->postJson(route('reservations.reject', $pending), [])->assertForbidden();
        $this->patchJson(route('reservations.edit-accepted', $accepted), [])->assertForbidden();

        $inactiveSuper = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
        $this->actingAs($inactiveSuper)->postJson(route('reservations.accept', $pending), [])->assertRedirect(route('login'));
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_reservation_list_permission_restriction_blocks_direct_page_access(): void
    {
        $this->taftAdmin->restrictedPermissions()->create(['permission' => AdminPermission::RESERVATIONS_VIEW->value]);
        $this->actingAs($this->taftAdmin)->get(route('reservations.index'))->assertForbidden();
        $this->actingAs($this->super)->get(route('reservations.index'))->assertOk();
    }
}
