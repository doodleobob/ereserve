<?php

namespace Tests\Feature;

use App\Models\EquipmentCharge;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\EquipmentChargeCreated;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EquipmentChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $resident;
    private Facility $equipment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
        $this->equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'charge-chairs', 'name' => 'Charge Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 20, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 0]);
    }

    private function usage(int $released = 10): \App\Models\ResourceUsage
    {
        $reservation = Reservation::create(['user_id' => $this->resident->id, 'barangay' => 'Taft', 'facility_id' => $this->equipment->id, 'facility_slug' => $this->equipment->slug, 'facility_name' => $this->equipment->name, 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-11-03', 'start_time' => '10:00', 'end_time' => '12:00', 'purpose' => 'Community activity', 'quantity' => 10, 'status' => 'accepted']);

        return $reservation->resourceUsage()->create(['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'resource_type' => 'Equipment', 'approved_quantity' => 10, 'released_quantity' => $released, 'returned_quantity' => 0]);
    }

    private function payload(\App\Models\ResourceUsage $usage, array $overrides = []): array
    {
        return array_replace(['resource_usage_id' => $usage->id, 'quantity' => 2, 'amount' => '75.50', 'reason' => 'Unreturned Equipment', 'notes' => 'Counted after activity', 'request_key' => (string) Str::uuid()], $overrides);
    }

    public function test_admin_charge_form_is_available_in_modal_and_validation_reopens_it(): void
    {
        $usage = $this->usage();
        $this->actingAs($this->admin)->get(route('equipment-charges.index'))
            ->assertOk()->assertSee('Record Charge')->assertSee('record-equipment-charge')
            ->assertSee('data-equipment-charge-form')->assertDontSee('Record Unpaid Charge');

        $this->post(route('equipment-charges.store'), $this->payload($usage, ['reason' => 'Other']))
            ->assertSessionHasErrors('reason_other');
        $this->get(route('equipment-charges.index'))->assertOk()
            ->assertSee('data-charge-auto-open', false)->assertSee('Usage #'.$usage->id)->assertSee('value="2"', false);
    }

    public function test_admin_creates_manual_unpaid_charge_and_resident_is_notified(): void
    {
        $usage = $this->usage();
        $this->actingAs($this->admin)->post(route('equipment-charges.store'), $this->payload($usage))->assertRedirect();
        $this->assertDatabaseHas('equipment_charges', ['resource_usage_id' => $usage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => '75.50', 'status' => 'unpaid']);
        Notification::assertSentTo($this->resident, EquipmentChargeCreated::class);
    }

    public function test_duplicate_submission_is_idempotent_and_cannot_charge_same_outstanding_quantity_twice(): void
    {
        $usage = $this->usage();
        $data = $this->payload($usage);
        $this->actingAs($this->admin)->post(route('equipment-charges.store'), $data)->assertRedirect();
        $this->post(route('equipment-charges.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('equipment_charges', 1);
        $this->post(route('equipment-charges.store'), $this->payload($usage, ['quantity' => 9]))->assertSessionHasErrors('quantity');
    }

    public function test_reason_options_and_other_reason_are_stored_and_displayed(): void
    {
        $usage = $this->usage();
        $payload = $this->payload($usage, ['reason' => 'Other', 'reason_other' => 'Missing transport case']);
        $this->actingAs($this->admin)->post(route('equipment-charges.store'), $payload)->assertRedirect();
        $charge = EquipmentCharge::firstOrFail();
        $this->assertSame('Other: Missing transport case', $charge->reason);
        $this->get(route('equipment-charges.index'))->assertOk()->assertSee('Other: Missing transport case');
        $this->get(route('equipment-charges.show', $charge))->assertOk()->assertSee('Other: Missing transport case');
        $this->assertSame(20, $this->equipment->fresh()->total_quantity);
        $this->assertSame(10, $usage->fresh()->outstanding());
    }

    public function test_validation_and_scope_block_invalid_amount_quantity_and_cross_barangay_usage(): void
    {
        $usage = $this->usage();
        foreach ([['amount' => 0, 'message' => 'The amount must be greater than 0.'], ['quantity' => 0, 'message' => 'The quantity field must be at least 1.'], ['quantity' => 11, 'message' => 'The charge quantity exceeds the uncharged outstanding quantity.'], ['reason' => '', 'message' => 'The reason field is required.'], ['reason' => 'Other', 'message' => 'A custom reason is required.']] as $case) {
            $override = array_diff_key($case, ['message' => true]);
            $this->actingAs($this->admin)->post(route('equipment-charges.store'), $this->payload($usage, $override))->assertSessionHasErrors();
        }
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $response = $this->actingAs($otherAdmin)->postJson(route('equipment-charges.store'), $this->payload($usage));
        $response->assertUnprocessable()->assertJsonValidationErrors('resource_usage_id');
        $this->assertDatabaseCount('equipment_charges', 0);
    }

    public function test_residents_only_view_their_own_charges_and_cannot_modify_them(): void
    {
        $usage = $this->usage();
        $charge = EquipmentCharge::create(['resource_usage_id' => $usage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => 75.50, 'reason' => 'Not returned', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
        $this->actingAs($this->resident)->get(route('equipment-charges.show', $charge))->assertOk()->assertSee('Charge Chairs');
        $other = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $this->actingAs($other)->get(route('equipment-charges.show', $charge))->assertForbidden();
        $this->actingAs($this->resident)->get(route('equipment-charges.edit', $charge))->assertForbidden();
        $this->patch(route('equipment-charges.update', $charge), [])->assertForbidden();
        $this->post(route('equipment-charges.store'), [])->assertForbidden();
    }

    public function test_super_admin_has_no_charge_operational_access(): void
    {
        $usage = $this->usage();
        $charge = EquipmentCharge::create(['resource_usage_id' => $usage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => 75.50, 'reason' => 'Not returned', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->get(route('equipment-charges.index'))->assertForbidden();
        $this->get(route('equipment-charges.show', $charge))->assertForbidden();
    }

    public function test_edits_are_audited_and_paid_status_is_not_available(): void
    {
        $usage = $this->usage();
        $charge = EquipmentCharge::create(['resource_usage_id' => $usage->id, 'created_by' => $this->admin->id, 'quantity' => 2, 'amount' => 75.50, 'reason' => 'Not returned', 'status' => 'unpaid', 'request_key' => (string) Str::uuid()]);
        $this->actingAs($this->admin)->patch(route('equipment-charges.update', $charge), ['quantity' => 3, 'amount' => '85.00', 'reason' => 'Unreturned Equipment', 'notes' => '', 'status' => 'waived'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('equipment_charge_changes', 1);
        $this->patch(route('equipment-charges.update', $charge), ['quantity' => 3, 'amount' => '85.00', 'reason' => 'Unreturned Equipment', 'status' => 'paid'])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->get(route('equipment-charges.show', $charge))->assertOk()->assertSee('Change History');
    }
}
