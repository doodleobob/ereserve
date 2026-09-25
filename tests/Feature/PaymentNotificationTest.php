<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private User $admin;

    private User $otherAdmin;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->facility = Facility::create(['barangay' => 'Washington', 'slug' => 'court', ...$this->facilityData()]);
    }

    private function facilityData(): array
    {
        return ['name' => 'Covered Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => '500.00'];
    }

    private function submit(): Reservation
    {
        $this->actingAs($this->resident)->post(route('reservations.store', 'court'), [
            'reservation_date' => today()->addDay()->toDateString(), 'start_time' => '14:00', 'end_time' => '17:00',
            'purpose' => 'Meeting', 'attendees' => 5, 'total_payment' => '1.00', 'hourly_rate_snapshot' => '1.00',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Reservation::latest('id')->firstOrFail();
    }

    public function test_admin_rates_are_validated_scoped_and_visible(): void
    {
        $data = $this->facilityData();
        $this->actingAs($this->admin)->post(route('facilities.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame('500.00', Facility::latest('id')->first()->hourly_rate);
        $this->actingAs($this->admin)->patch(route('facilities.update', 'court'), [...$data, 'hourly_rate' => '1250.00'])->assertSessionHasNoErrors();
        $this->actingAs($this->resident)->get(route('facilities'))->assertSee('₱1,250.00 / hour');
        $this->get(route('facilities.show', 'court'))->assertSee('₱1,250.00 / hour');
        $this->patch(route('facilities.update', 'court'), $data)->assertForbidden();
        $this->post(route('facilities.store'), $data)->assertForbidden();
        $this->actingAs($this->otherAdmin)->patch(route('facilities.update', 'court'), $data)->assertNotFound();
        foreach (['-1', 'invalid', '0.001', '100000000', '1e2'] as $rate) {
            $this->actingAs($this->admin)->patch(route('facilities.update', 'court'), [...$data, 'hourly_rate' => $rate])->assertSessionHasErrors('hourly_rate');
        }
    }

    public function test_snapshot_pending_visibility_and_barangay_notification(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $r = $this->submit();
        $this->facility->update(['hourly_rate' => '600.00']);
        $this->assertSame('500.00', $r->fresh()->hourly_rate_snapshot);
        $this->assertNull($r->total_payment);
        $this->assertSame('1500.00', $r->calculatedAmount());
        $this->assertSame(180, $r->durationMinutes());
        $this->assertSame(1, $this->admin->unreadNotifications()->count());
        $this->assertSame(0, $this->otherAdmin->notifications()->count());
        $this->assertSame(0, $super->notifications()->count());
        $this->get(route('reservations.index'))->assertSee('₱500.00 / hour')->assertDontSee('Total Payment')->assertDontSee('Calculated Amount');
        $this->actingAs($this->admin)->get(route('reservations.index'))->assertSee('180 minutes')->assertSee('Calculated Amount')->assertSee('₱1,500.00');
        $this->get(route('notifications.index'))->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.data.0.data.event', 'submitted');
        $this->get(route('dashboard'))->assertSee('notification-bell')->assertSee('notification-toasts')->assertSee('notifications.js');
        $r->end_time = '14:01';
        $this->assertSame('8.33', $r->calculatedAmount());
        $another = User::factory()->create(['barangay' => 'Washington']);
        $this->resident = $another;
        $this->assertSame('600.00', $this->submit()->hourly_rate_snapshot);
    }

    public function test_acceptance_and_corrections_notify_once_with_actual_payment_details(): void
    {
        $r = $this->submit();
        $another = User::factory()->create(['barangay' => 'Washington']);
        $this->actingAs($this->admin)->patch(route('reservations.payment', $r), ['total_payment' => '1400.00'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertSame(0, $this->resident->notifications()->count());
        $this->post(route('reservations.accept', $r), ['total_payment' => '1400.00', 'payment_confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame('1400.00', $r->fresh()->total_payment);
        $notification = $this->resident->notifications()->firstOrFail();
        $this->assertSame('accepted', $notification->data['event']);
        $this->assertSame('500.00', $notification->data['hourly_rate']);
        $this->assertSame('1400.00', $notification->data['total_payment']);
        $this->assertContains('Covered Court', $notification->data['details']);
        $this->assertContains('2:00 PM – 5:00 PM', $notification->data['details']);
        $this->assertContains(today()->addDay()->format('F j, Y'), $notification->data['details']);
        $this->assertSame('Reservation Booked', $notification->data['title']);
        $this->assertContains('Total Paid: ₱1,400.00', $notification->data['details']);
        $this->assertStringNotContainsString('valid ID', json_encode($notification->data));
        $this->assertStringNotContainsString('complete your payment', json_encode($notification->data));
        $this->post(route('reservations.accept', $r), ['total_payment' => '99.00']);
        $this->assertSame(1, $this->resident->notifications()->count());
        $this->patch(route('reservations.payment', $r), ['total_payment' => '1300.00'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $r->fresh()->status);
        $updated = $this->resident->notifications()->where('data->event', 'payment_updated')->firstOrFail();
        $this->assertSame('1400.00', $updated->data['previous_total']);
        $this->assertSame('1300.00', $updated->data['total_payment']);
        $this->patch(route('reservations.payment', $r), ['total_payment' => '1300']);
        $this->assertSame(2, $this->resident->notifications()->count());
        $this->assertSame(0, $another->notifications()->count());
        $this->actingAs($this->resident)->get(route('reservations.index'))->assertSee('Total Paid: ₱1,300.00')->assertSee('Booked')->assertSee('Duration:')->assertDontSee('Pay at Barangay')->assertDontSee('valid ID')->assertDontSee('name="total_payment"', false);
    }

    public function test_payment_validation_and_authorization(): void
    {
        $r = $this->submit();
        foreach (['reservations.payment', 'reservations.accept'] as $route) {
            $method = $route === 'reservations.payment' ? 'patch' : 'post';
            foreach ([$this->resident, $this->otherAdmin] as $unauthorized) {
                $this->actingAs($unauthorized)->{$method}(route($route, $r), ['total_payment' => '1.00'])->assertForbidden();
            }
            foreach (['-1', 'invalid', '1.001', '10000000000', '1e2', null] as $amount) {
                $this->actingAs($this->admin)->{$method}(route($route, $r), ['total_payment' => $amount])->assertSessionHasErrors('total_payment');
            }
        }
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertNull($r->fresh()->total_payment);
        $this->assertSame(0, $this->resident->notifications()->count());
        $this->actingAs($this->admin)->post(route('reservations.accept', $r), ['total_payment' => '0.00', 'payment_confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $r->fresh()->total_payment);
    }

    public function test_acceptance_requires_explicit_payment_confirmation(): void
    {
        $r = $this->submit();
        $this->actingAs($this->admin)->get(route('reservations.index'))
            ->assertSee('Confirm Reservation')->assertSee('has been received')
            ->assertSee('Confirm &amp; Accept', false)->assertSee('data-cancel-payment', false);
        foreach ([null, '0', 'false'] as $confirmed) {
            $this->post(route('reservations.accept', $r), ['total_payment' => '1400.00', 'payment_confirmed' => $confirmed])
                ->assertSessionHasErrors('payment_confirmed');
            $this->assertSame('pending', $r->fresh()->status);
            $this->assertNull($r->fresh()->total_payment);
            $this->assertSame(0, $this->resident->notifications()->count());
        }
        $this->post(route('reservations.accept', $r), ['total_payment' => '-1', 'payment_confirmed' => '1'])->assertSessionHasErrors('total_payment');
        $this->actingAs($this->otherAdmin)->post(route('reservations.accept', $r), ['total_payment' => '1400.00', 'payment_confirmed' => '1'])->assertForbidden();
        $this->actingAs($this->resident)->post(route('reservations.accept', $r), ['total_payment' => '1400.00', 'payment_confirmed' => '1'])->assertForbidden();
    }

    public function test_pending_and_rejected_reservations_do_not_display_total_paid(): void
    {
        $r = $this->submit();
        $this->get(route('reservations.index'))->assertDontSee('Total Paid')->assertSee('Pending');
        $this->actingAs($this->admin)->post(route('reservations.reject', $r))->assertSessionHasNoErrors();
        $this->actingAs($this->resident)->get(route('reservations.index'))->assertSee('Rejected')->assertDontSee('Total Paid')->assertDontSee('Payment Confirmed');
        $this->assertSame(0, $this->resident->notifications()->count());
    }

    public function test_historical_notification_display_removes_old_payment_instructions(): void
    {
        $r = $this->submit();
        $r->update(['status' => 'accepted', 'total_payment' => '1400.00']);
        $this->resident->notify(new ReservationActivity($r, 'accepted'));
        $notification = $this->resident->notifications()->firstOrFail();
        $data = $notification->data;
        $data['title'] = 'Reservation Accepted';
        $data['details'] = ['Covered Court', 'Total Payment: ₱1,400.00', 'Payment Method: Pay at Barangay', 'Please proceed to your barangay and look for the assigned staff to complete your payment. Please bring a valid ID for verification.'];
        $notification->update(['data' => $data]);
        $this->get(route('notifications.index'))->assertJsonPath('notifications.data.0.data.title', 'Reservation Booked')
            ->assertJsonPath('notifications.data.0.data.details.1', 'Total Paid: ₱1,400.00')
            ->assertDontSee('valid ID')->assertDontSee('Pay at Barangay');
        $this->assertSame($data, $notification->fresh()->data);
    }

    public function test_notification_ownership_read_state_persistence_and_changed_barangay(): void
    {
        $r = $this->submit();
        $notification = $this->admin->notifications()->firstOrFail();
        $this->actingAs($this->otherAdmin)->post(route('notifications.open', $notification->id))->assertNotFound();
        $this->actingAs($this->resident)->post(route('notifications.open', $notification->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('notifications.index'))->assertJsonPath('unread_count', 1);
        $this->get(route('notifications.index'))->assertJsonPath('unread_count', 1);
        $this->post(route('notifications.open', $notification->id))->assertRedirect(route('reservations.index', ['reservation' => $r->id]));
        $this->get(route('notifications.index'))->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.data.0.read', true);
        $this->admin->notify(new ReservationActivity($r, 'submitted'));
        $this->post(route('notifications.read-all'))->assertNoContent();
        $this->get(route('notifications.index'))->assertJsonPath('unread_count', 0);
        $this->assertSame(2, $this->admin->notifications()->count());
        $this->admin->update(['role' => 'user']);
        $this->get(route('notifications.index'))->assertJsonPath('notifications.total', 0);
        $this->post(route('notifications.open', $notification->id))->assertNotFound();
        $this->admin->update(['role' => 'admin']);
        $this->admin->update(['barangay' => 'Taft']);
        $this->get(route('notifications.index'))->assertJsonPath('notifications.total', 0);
        $this->post(route('notifications.open', $notification->id))->assertNotFound();
    }

    public function test_legacy_reservations_do_not_invent_historical_prices(): void
    {
        $r = $this->submit();
        $r->update(['hourly_rate_snapshot' => null]);
        $this->assertNull($r->calculatedAmount());
        $this->actingAs($this->admin)->get(route('reservations.index'))->assertSee('Not recorded');
        $this->post(route('reservations.accept', $r), ['total_payment' => '250.00', 'payment_confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()->hourly_rate_snapshot);
        $this->assertSame('250.00', $r->fresh()->total_payment);
    }
}
