<?php

namespace Tests\Feature;

use App\Models\EquipmentCharge;
use App\Models\EquipmentChargePayment;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AdminPermission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EquipmentChargePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $resident;
    private Facility $equipment;
    private Reservation $reservation;
    private EquipmentCharge $charge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
        $this->equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'payment-chairs', 'name' => 'Payment Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 20, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 0]);
        $this->reservation = Reservation::create(['user_id' => $this->resident->id, 'barangay' => 'Taft', 'facility_id' => $this->equipment->id, 'facility_slug' => $this->equipment->slug, 'facility_name' => $this->equipment->name, 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00', 'purpose' => 'Community activity', 'quantity' => 10, 'status' => 'accepted', 'total_payment' => '200.00']);
        $usage = $this->reservation->resourceUsage()->create(['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'resource_type' => 'Equipment', 'approved_quantity' => 10, 'released_quantity' => 10, 'returned_quantity' => 0]);
        $this->charge = EquipmentCharge::create(['resource_usage_id' => $usage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => '100.00', 'reason' => 'Damaged Equipment', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
    }

    private function record(string $amount, string $key, string $date = '2026-11-03')
    {
        return $this->actingAs($this->admin)->post(route('equipment-charges.payments.store', $this->charge), [
            'amount' => $amount, 'payment_date' => $date, 'request_key' => $key,
        ]);
    }

    public function test_partial_and_full_payments_update_balance_and_status_without_touching_reservation_payments_or_usage(): void
    {
        $usageBefore = $this->charge->usage->only(['returned_quantity', 'released_quantity']);
        $this->record('35.25', (string) Str::uuid())->assertRedirect(route('payments.index'));
        $this->assertDatabaseHas('equipment_charge_payments', ['equipment_charge_id' => $this->charge->id, 'amount' => '35.25', 'recorded_by' => $this->admin->id]);
        $this->assertSame('unpaid', $this->charge->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($usageBefore, $this->charge->usage->fresh()->only(['returned_quantity', 'released_quantity']));

        $this->get(route('payments.index', ['type' => 'equipment_charge']))->assertOk()->assertSee('CHG-#'.$this->charge->id)->assertSee('Payment Chairs')->assertSee('Balance')->assertSee('64.75');
        $this->record('64.75', (string) Str::uuid())->assertRedirect(route('payments.index'));
        $this->assertSame('paid', $this->charge->fresh()->status);
        $this->assertSame('100.00', number_format((float) $this->charge->payments()->sum('amount'), 2, '.', ''));
        $this->assertDatabaseCount('payments', 0);
        $this->get(route('payments.index', ['type' => 'equipment_charge', 'status' => 'paid']))->assertOk()->assertSee('Paid')->assertSee('0.00');
        $this->get(route('equipment-charges.show', $this->charge))->assertOk()->assertSee('Payment History')->assertSee('35.25')->assertSee('64.75');
    }

    public function test_payment_submission_is_idempotent_and_overpayment_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $this->record('80.00', $key)->assertRedirect();
        $this->record('80.00', $key)->assertRedirect();
        $this->assertDatabaseCount('equipment_charge_payments', 1);
        $this->record('25.00', (string) Str::uuid())->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('equipment_charge_payments', 1);
    }

    public function test_residents_only_see_their_own_central_payments_and_cannot_record_charge_payments(): void
    {
        $otherResident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $otherReservation = Reservation::create(['user_id' => $otherResident->id, 'barangay' => 'Taft', 'facility_id' => $this->equipment->id, 'facility_slug' => $this->equipment->slug, 'facility_name' => $this->equipment->name, 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00', 'purpose' => 'Other', 'quantity' => 1, 'status' => 'accepted']);
        $otherUsage = $otherReservation->resourceUsage()->create(['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'resource_type' => 'Equipment', 'approved_quantity' => 1, 'released_quantity' => 1, 'returned_quantity' => 0]);
        EquipmentCharge::create(['resource_usage_id' => $otherUsage->id, 'created_by' => $this->admin->id, 'quantity' => 1, 'amount' => '50.00', 'reason' => 'Lost Equipment', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
        $this->actingAs($this->resident)->get(route('payments.index'))->assertOk()->assertViewHas('payments', fn ($rows) => $rows->total() === 1)->assertDontSee('CHG-#2');
        $this->actingAs($this->resident)->post(route('equipment-charges.payments.store', $this->charge), [])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']))
            ->postJson(route('equipment-charges.payments.store', $this->charge), [])->assertForbidden();
    }

    public function test_super_admin_records_cross_barangay_charge_payments_and_admin_permission_restrictions_apply(): void
    {
        $foreignEquipment = Facility::create(['barangay' => 'Washington', 'slug' => 'foreign-payment-chairs', 'name' => 'Foreign Payment Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 20, 'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => 0]);
        $foreignReservation = Reservation::create(['user_id' => $this->resident->id, 'barangay' => 'Taft', 'facility_id' => $foreignEquipment->id, 'facility_slug' => $foreignEquipment->slug, 'facility_name' => $foreignEquipment->name, 'category' => 'Equipment', 'location' => 'Washington', 'reservation_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00', 'purpose' => 'Community activity', 'quantity' => 10, 'status' => 'accepted']);
        $foreignUsage = $foreignReservation->resourceUsage()->create(['facility_id' => $foreignEquipment->id, 'barangay' => 'Washington', 'resource_type' => 'Equipment', 'approved_quantity' => 10, 'released_quantity' => 10, 'returned_quantity' => 0]);
        $foreignCharge = EquipmentCharge::create(['resource_usage_id' => $foreignUsage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => '100.00', 'reason' => 'Damaged Equipment', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);

        $this->actingAs($super)->get(route('payments.index', ['type' => 'equipment_charge', 'barangay' => 'Washington']))
            ->assertOk()->assertSee('Foreign Payment Chairs')->assertSee('Record Payment')->assertSee('Washington');
        $this->post(route('equipment-charges.payments.store', $foreignCharge), ['amount' => '40.00', 'payment_date' => '2026-11-03', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $payment = $foreignCharge->payments()->firstOrFail();
        $this->assertSame($super->id, $payment->recorded_by);
        $this->assertSame('unpaid', $foreignCharge->fresh()->status);
        $this->assertDatabaseCount('equipment_charge_changes', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->actingAs($this->admin)->post(route('equipment-charges.payments.store', $foreignCharge), ['amount' => '20.00', 'payment_date' => '2026-11-03', 'request_key' => (string) Str::uuid()])->assertForbidden();

        $localCharge = $this->charge;
        $this->admin->restrictedPermissions()->create(['permission' => AdminPermission::EQUIPMENT_CHARGES_MANAGE->value]);
        $this->actingAs($this->admin)->post(route('equipment-charges.payments.store', $localCharge), ['amount' => '20.00', 'payment_date' => '2026-11-03', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->get(route('equipment-charges.index'))->assertForbidden();
        $this->get(route('payments.index', ['type' => 'equipment_charge']))->assertViewHas('payments', fn ($rows) => $rows->where('payment_type', 'equipment_charge')->isEmpty());
    }

    public function test_central_filters_include_both_payment_types_and_reservation_status_is_unchanged(): void
    {
        $reservationPayment = $this->reservation->payment()->create(['amount' => '10.00', 'payment_status' => 'refund_pending']);
        $this->actingAs($this->admin)->post(route('equipment-charges.payments.store', $this->charge), ['amount' => '20.00', 'payment_date' => '2026-11-03', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get(route('payments.index'))->assertViewHas('payments', fn ($rows) => $rows->total() === 2);
        $this->get(route('payments.index', ['type' => 'reservation', 'status' => 'refund_pending']))->assertViewHas('payments', fn ($rows) => $rows->total() === 1 && $rows->first()->payment_id === $reservationPayment->id);
        $this->get(route('payments.index', ['type' => 'equipment_charge', 'search' => 'CHG-'.$this->charge->id]))->assertViewHas('payments', fn ($rows) => $rows->total() === 1 && $rows->first()->charge_id === $this->charge->id);
        $this->assertSame('refund_pending', $reservationPayment->fresh()->payment_status);
        $this->assertSame('200.00', $this->reservation->fresh()->total_payment);
        $this->assertDatabaseCount('payments', 1);
    }
}
