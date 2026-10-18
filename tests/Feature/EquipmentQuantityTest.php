<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationAvailability;
use App\Support\ReservationPeriod;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EquipmentQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Facility $equipment;

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
        $this->equipment = Facility::create([
            'barangay' => 'Taft', 'slug' => 'chairs', 'name' => 'Chairs', 'category' => 'Equipment',
            'description' => 'Plastic chairs', 'capacity' => 200, 'total_quantity' => 100,
            'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100,
        ]);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['reservation_date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '10:00', 'purpose' => 'Meeting', 'quantity' => 20], $overrides);
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_replace([
            ...$this->data(), 'user_id' => $this->resident->id, 'facility_id' => $this->equipment->id,
            'barangay' => 'Taft', 'facility_slug' => 'chairs', 'facility_name' => 'Chairs', 'category' => 'Equipment',
            'location' => 'Taft', 'attendees' => null, 'status' => 'accepted', 'hourly_rate_snapshot' => 100,
        ], $overrides));
    }

    private function available(array $overrides = []): int
    {
        $data = $this->data($overrides);

        return ReservationAvailability::availableQuantity($this->equipment->fresh(), new ReservationPeriod($data['reservation_date'], $data['start_time'], $data['end_time']));
    }

    private function accept(Reservation $reservation)
    {
        return $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), ['total_payment' => 200, 'payment_confirmed' => 1]);
    }

    public function test_submission_requires_units_and_checks_remaining_stock_without_using_attendees(): void
    {
        $this->booking(['quantity' => 30, 'user_id' => $this->admin->id]);
        foreach ([null, 0, -1, 1.2, 101, 80] as $quantity) {
            $this->actingAs($this->resident)->postJson(route('reservations.store', 'chairs'), $this->data(['quantity' => $quantity]))
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->postJson(route('reservations.store', 'chairs'), $this->data(['quantity' => 80]))
            ->assertJsonPath('errors.quantity.0', 'Only 70 units are available for the selected date and time.');
        $this->postJson(route('reservations.store', 'chairs'), $this->data(['quantity' => 70, 'attendees' => 9999]))->assertCreated();
        $this->assertDatabaseHas('reservations', ['user_id' => $this->resident->id, 'quantity' => 70, 'attendees' => null, 'status' => 'pending']);
        $this->assertSame(70, $this->available());
    }

    public function test_equipment_creation_uses_verified_stock_without_inventing_people_capacity(): void
    {
        $data = ['name' => 'Tables', 'category' => 'Equipment', 'description' => 'Folding tables', 'location' => 'Taft', 'status' => 'Available', 'total_quantity' => 75, 'capacity' => 999];
        $this->actingAs($this->resident)->postJson(route('facilities.store'), $data)->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('facilities.store'), $data)->assertCreated();
        $this->assertDatabaseHas('facilities', ['name' => 'Tables', 'total_quantity' => 75, 'capacity' => null, 'barangay' => 'Taft']);
    }

    public function test_unverified_legacy_stock_does_not_prevent_manual_availability_changes(): void
    {
        $this->equipment->update(['total_quantity' => null]);
        $data = $this->equipment->only(['name', 'category', 'description', 'location']);
        $this->actingAs($this->admin)->patchJson(route('facilities.update', 'chairs'), $data + ['status' => 'Unavailable'])->assertOk();
        $this->assertNull($this->equipment->fresh()->total_quantity);
        $this->assertSame(200, $this->equipment->fresh()->capacity);
        $this->assertSame('Unavailable', $this->equipment->fresh()->status);
    }

    public function test_peak_concurrency_handles_adjacent_partial_identical_and_overnight_intervals(): void
    {
        $this->booking(['quantity' => 40, 'end_time' => '09:00']);
        $this->booking(['quantity' => 50, 'start_time' => '09:00']);
        $this->assertSame(50, $this->available());
        $this->booking(['quantity' => 20, 'start_time' => '08:45', 'end_time' => '09:15']);
        $this->assertSame(30, $this->available());
        $this->assertSame(40, $this->available(['start_time' => '08:45', 'end_time' => '09:00']));
        $this->booking(['quantity' => 35, 'start_time' => '23:37', 'end_time' => '01:12']);
        $this->booking(['quantity' => 25, 'reservation_date' => '2026-10-21', 'start_time' => '00:13', 'end_time' => '02:00']);
        $this->assertSame(40, $this->available(['start_time' => '23:50', 'end_time' => '01:15']));
        $this->assertSame(75, $this->available(['reservation_date' => '2026-10-21', 'start_time' => '01:12', 'end_time' => '02:00']));
        $this->assertSame(100, $this->available(['reservation_date' => '2026-10-21', 'start_time' => '02:00', 'end_time' => '03:00']));
    }

    public function test_acceptance_rechecks_stock_and_preserves_payment_and_pending_requests_on_failure(): void
    {
        $first = $this->booking(['status' => 'pending', 'quantity' => 30]);
        $second = $this->booking(['status' => 'pending', 'quantity' => 40]);
        $third = $this->booking(['status' => 'pending', 'quantity' => 40]);
        $this->assertSame(100, $this->available());
        $this->accept($first)->assertOk();
        $this->accept($second)->assertOk();
        $this->accept($third)->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Only 30 units are available for the selected date and time.');
        $this->assertSame('pending', $third->fresh()->status);
        $this->assertNull($third->fresh()->payment);
        $this->assertSame('200.00', $first->fresh()->calculatedAmount());
        $this->assertSame('200.00', $first->fresh()->payment->amount);
        $third->update(['quantity' => 20]);
        $this->accept($third)->assertOk();
        $this->assertSame(10, $this->available());
        $this->assertSame(100, $this->equipment->fresh()->total_quantity);
    }

    public function test_rescheduling_and_cancellation_derive_allocations_without_double_release(): void
    {
        $first = $this->booking(['status' => 'pending', 'quantity' => 60]);
        $this->accept($first)->assertOk();
        $this->booking(['quantity' => 60, 'start_time' => '11:00', 'end_time' => '12:00']);
        $url = route('reservations.edit-accepted', $first);
        $this->patchJson($url, ['action' => 'reschedule', ...$this->data(['start_time' => '11:00', 'end_time' => '12:00'])])->assertUnprocessable();
        $this->patchJson($url, ['action' => 'reschedule', ...$this->data(['start_time' => '10:00', 'end_time' => '11:00'])])->assertOk();
        $this->assertSame(100, $this->available());
        $this->assertSame(40, $this->available(['start_time' => '10:00', 'end_time' => '11:00']));
        $cancel = ['action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'User Requested Cancellation'];
        $this->patchJson($url, $cancel)->assertOk();
        $this->patchJson($url, $cancel)->assertUnprocessable();
        $this->assertSame(100, $this->available(['start_time' => '10:00', 'end_time' => '11:00']));
        $this->assertSame('refund_pending', $first->fresh()->payment->payment_status);
        $this->assertSame('200.00', $first->fresh()->total_payment);
    }

    public function test_legacy_records_remain_exclusive_and_unverified_stock_is_not_inferred_from_capacity(): void
    {
        $legacy = $this->booking(['quantity' => null, 'attendees' => 23, 'total_payment' => 375, 'facility_id' => null]);
        $this->assertSame(0, $this->available());
        $this->assertNull($legacy->fresh()->quantity);
        $this->assertSame(23, $legacy->fresh()->attendees);
        $this->assertSame('375.00', $legacy->fresh()->total_payment);
        $legacy->update(['status' => 'cancelled']);
        $this->assertSame(100, $this->available());
        $this->equipment->update(['total_quantity' => null]);
        $this->actingAs($this->resident)->postJson(route('reservations.store', 'chairs'), $this->data())
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(200, $this->equipment->fresh()->capacity);
    }

    public function test_official_use_partially_allocates_and_only_creates_conflicts_when_inventory_is_exceeded(): void
    {
        $reservation = $this->booking(['status' => 'pending', 'quantity' => 50]);
        $this->accept($reservation)->assertOk();
        $data = ['facility_id' => $this->equipment->id, 'date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '10:00', 'purpose' => 'Private official purpose', 'quantity' => 40];
        $id = $this->postJson(route('official-uses.store'), $data)->assertCreated()->json('id');
        $this->assertSame(10, $this->available());
        $this->assertDatabaseCount('official_use_conflicts', 0);
        $this->patchJson(route('official-uses.update', $id), array_replace($data, ['quantity' => 60]))->assertOk();
        $this->assertSame('conflict', OfficialUse::find($id)->status);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertSame('paid', $reservation->fresh()->payment->payment_status);
        $this->assertSame(0, $this->available());
        $this->patchJson(route('official-uses.update', $id), $data)->assertOk();
        $this->assertSame('active', OfficialUse::find($id)->status);
        $this->assertSame('resolved', $reservation->officialUseConflicts()->first()->resolution);
        $this->assertSame('paid', $reservation->fresh()->payment->payment_status);
    }

    public function test_official_uses_share_quantity_and_resolving_one_resident_releases_other_conflicts(): void
    {
        $first = $this->booking(['quantity' => 40]);
        $second = $this->booking(['quantity' => 40]);
        $data = ['facility_id' => $this->equipment->id, 'date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '10:00', 'purpose' => 'Assembly', 'quantity' => 30];
        $id = $this->actingAs($this->admin)->postJson(route('official-uses.store'), $data)->assertCreated()->json('id');
        $this->assertDatabaseCount('official_use_conflicts', 2);
        $this->patchJson(route('reservations.edit-accepted', $first), ['action' => 'reschedule', ...$this->data(['start_time' => '10:00', 'end_time' => '11:00'])])->assertOk();
        $this->assertSame('resolved', $second->officialUseConflicts()->first()->resolution);
        $this->assertSame('active', OfficialUse::find($id)->status);
        $this->postJson(route('official-uses.store'), array_replace($data, ['quantity' => 70]))->assertCreated();
        $this->postJson(route('official-uses.store'), array_replace($data, ['quantity' => 1]))->assertUnprocessable();
        $this->assertSame(0, $this->available());
    }

    public function test_stock_increase_resolves_conflicts_and_manual_status_can_change_during_a_conflict(): void
    {
        $booking = $this->booking(['quantity' => 80]);
        $id = $this->actingAs($this->admin)->postJson(route('official-uses.store'), [
            'facility_id' => $this->equipment->id, 'date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '10:00', 'purpose' => 'Assembly', 'quantity' => 40,
        ])->assertCreated()->json('id');
        $data = $this->equipment->only(['name', 'category', 'description', 'location', 'status', 'total_quantity']);
        $this->patchJson(route('facilities.update', 'chairs'), array_replace($data, ['status' => 'Unavailable']))->assertOk();
        $this->assertSame('accepted', $booking->fresh()->status);
        $this->patchJson(route('facilities.update', 'chairs'), array_replace($data, ['total_quantity' => 120]))->assertOk();
        $this->assertSame('active', OfficialUse::find($id)->status);
        $this->assertSame('resolved', $booking->officialUseConflicts()->first()->resolution);
    }

    public function test_official_use_does_not_cancel_a_pending_request_for_a_shortage_outside_its_interval(): void
    {
        $pending = $this->booking(['status' => 'pending', 'quantity' => 50]);
        $this->booking(['quantity' => 100, 'start_time' => '09:00', 'end_time' => '10:00']);
        $this->actingAs($this->admin)->postJson(route('official-uses.store'), [
            'facility_id' => $this->equipment->id, 'date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '09:00', 'purpose' => 'Assembly', 'quantity' => 40,
        ])->assertCreated();
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('official_use_conflicts', 0);
    }

    public function test_management_validation_scoping_and_manual_availability_preserve_allocations(): void
    {
        $data = $this->equipment->only(['name', 'category', 'description', 'location', 'status', 'total_quantity']);
        foreach ([0, -1, 1.5, null] as $quantity) {
            $this->actingAs($this->admin)->patchJson(route('facilities.update', 'chairs'), array_replace($data, ['total_quantity' => $quantity]))
                ->assertUnprocessable()->assertJsonValidationErrors('total_quantity');
        }
        $this->actingAs($this->resident)->patchJson(route('facilities.update', 'chairs'), $data)->assertForbidden();
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($otherAdmin)->patchJson(route('facilities.update', 'chairs'), $data)->assertNotFound();
        $booking = $this->booking(['quantity' => 60]);
        $this->actingAs($this->admin)->patchJson(route('facilities.update', 'chairs'), array_replace($data, ['total_quantity' => 50]))->assertUnprocessable();
        $this->patchJson(route('facilities.update', 'chairs'), array_replace($data, ['status' => 'Unavailable']))->assertOk();
        $this->actingAs($this->resident)->postJson(route('reservations.store', 'chairs'), $this->data())->assertUnprocessable();
        $this->assertSame('accepted', $booking->fresh()->status);
        $this->assertSame(100, $this->equipment->fresh()->total_quantity);
    }

    public function test_details_calendar_and_live_availability_show_units_without_personal_information(): void
    {
        $this->booking(['quantity' => 30]);
        $response = $this->actingAs($this->resident)->get(route('facilities.show', ['slug' => 'chairs', 'date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '10:00']))->assertOk();
        $response->assertSee('Total Quantity')->assertSee('Quantity Requested')->assertSee('70 units')->assertDontSee('name="attendees"', false);
        $this->getJson(route('facilities.availability', ['slug' => 'chairs', ...$this->data()]))->assertOk()->assertJsonPath('available_quantity', 70);
        $this->get(route('calendar', ['facility' => 'chairs', 'date' => '2026-10-20']))->assertOk()
            ->assertSee('30 of 100 units booked')->assertDontSee('Submit Reservation')
            ->assertViewHas('calendarEvents', fn ($events) => ! str_contains($events->toJson(), $this->resident->email));
        $this->equipment->update(['status' => 'Unavailable']);
        $this->get(route('calendar', ['facility' => 'chairs', 'date' => '2026-10-20']))->assertSee('30 of 100 units booked');
    }

    public function test_pending_edit_can_correct_quantity_and_keeps_stock_and_financial_fields_protected(): void
    {
        $pending = $this->booking(['status' => 'pending', 'quantity' => 20]);
        $this->actingAs($this->resident)->patchJson(route('reservations.update-pending', $pending), $this->data(['quantity' => 70, 'total_quantity' => 1000, 'status' => 'accepted', 'total_payment' => 1]))->assertOk();
        $this->assertSame(70, (int) $pending->fresh()->quantity);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->total_payment);
        $this->assertSame(100, $this->equipment->fresh()->total_quantity);
    }

    public function test_facility_and_equipment_forms_have_distinct_fields_and_can_render_browser_fixtures(): void
    {
        $facility = Facility::create([
            'barangay' => 'Taft', 'slug' => 'court', 'name' => 'Covered Court', 'category' => 'Facility',
            'description' => 'Community court', 'capacity' => 200, 'location' => 'Taft', 'status' => 'Available',
        ]);
        $this->booking(['quantity' => 30]);
        $responses = [];
        foreach (['chairs' => 'equipment-details', 'court' => 'facility-details'] as $slug => $name) {
            $responses[$name] = $this->actingAs($this->resident)->get(route('facilities.show', ['slug' => $slug, 'date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '10:00']))->assertOk();
        }
        $responses['facility-details']->assertSee('Maximum Capacity')->assertSee('name="attendees"', false)->assertDontSee('name="quantity"', false);
        $responses['equipment-details']->assertSee('Quantity Requested')->assertDontSee('name="attendees"', false);
        $responses['schedules'] = $this->get(route('calendar', ['facility' => 'chairs', 'date' => '2026-10-20']))->assertOk();
        $responses['management'] = $this->actingAs($this->admin)->get(route('facilities'))->assertOk();
        $responses['official-use'] = $this->get(route('official-uses.index'))->assertOk();
        if ($directory = getenv('EQUIPMENT_UI_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            foreach ($responses as $name => $response) {
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        }
    }
}
