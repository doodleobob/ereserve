<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ResourceUsageManagement;
use App\Support\ReservationAvailability;
use App\Support\ReservationPeriod;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EquipmentBufferTest extends TestCase
{
    use RefreshDatabase;

    private Facility $equipment;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(18, 0));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'buffer-chairs', 'name' => 'Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 160, 'location' => 'Taft', 'status' => 'Available']);
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_replace(['user_id' => $this->admin->id, 'facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'facility_slug' => 'buffer-chairs', 'facility_name' => 'Chairs', 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-10-23', 'start_time' => '19:00', 'end_time' => '20:00', 'purpose' => 'Meeting', 'quantity' => 100, 'status' => 'accepted'], $overrides));
    }

    private function available(string $start, string $end, string $date = '2026-10-23'): int
    {
        return ReservationAvailability::availableQuantity($this->equipment, new ReservationPeriod($date, $start, $end));
    }

    public function test_buffer_acceptance_and_exclusive_end_preserve_original_times(): void
    {
        $first = $this->booking();
        $this->assertSame(60, $this->available('20:00', '21:00'));
        $this->assertSame(160, $this->available('21:00', '22:00'));
        $pending = $this->booking(['status' => 'pending', 'quantity' => 140, 'start_time' => '20:00', 'end_time' => '21:00']);
        $this->actingAs($this->admin)->getJson(route('reservations.equipment-availability', $pending))
            ->assertOk()->assertJsonPath('summary.buffer_minutes', 60)->assertJsonPath('summary.allocated', 100)
            ->assertJsonPath('summary.effective_end', '2026-10-23T22:00:00+08:00');
        $url = route('reservations.accept', $pending);
        $data = ['total_payment' => 0, 'payment_confirmed' => 1];
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $pending->update(['quantity' => 60]);
        $this->postJson($url, $data)->assertOk();
        $this->assertSame('20:00', substr($first->fresh()->end_time, 0, 5));
    }

    public function test_peak_is_not_sum_of_nonconcurrent_allocations_and_request_buffer_is_checked(): void
    {
        $this->booking(['start_time' => '10:00', 'end_time' => '11:00', 'quantity' => 70]);
        $this->booking(['start_time' => '12:00', 'end_time' => '13:00', 'quantity' => 90]);
        $this->assertSame(70, $this->available('10:00', '13:00'));
        $this->assertSame(70, $this->available('11:00', '12:00'));
        $this->booking(['start_time' => '11:00', 'end_time' => '12:00', 'quantity' => 20]);
        $this->assertSame(50, $this->available('10:00', '13:00'));
        foreach (['cancelled', 'rejected', 'pending'] as $status) {
            $this->booking(['quantity' => 160, 'status' => $status]);
        }
        $this->assertSame(160, $this->available('19:00', '20:00'));
    }

    public function test_overnight_and_two_dates_back_are_included(): void
    {
        $this->booking(['reservation_date' => '2026-10-22', 'start_time' => '23:30', 'end_time' => '23:00']);
        $this->assertSame(60, $this->available('23:00', '23:30'));
        $this->assertSame(160, $this->available('00:00', '01:00', '2026-10-24'));
        $this->booking(['start_time' => '23:30', 'end_time' => '23:15']);
        $this->assertSame(60, $this->available('00:00', '01:00', '2026-10-25'));
        $this->assertSame(160, $this->available('00:15', '01:15', '2026-10-25'));
    }

    public function test_early_return_preserves_buffer_and_late_return_blocks_release_only(): void
    {
        $first = $this->booking();
        $record = fn ($reservation, $action, $quantity) => app(ResourceUsageManagement::class)->record($this->admin, $reservation, ['action' => $action, 'quantity' => $quantity, 'confirmed' => 1, 'request_key' => (string) Str::uuid()]);
        $record($first, 'release', 100);
        $record($first, 'return', 40);
        $this->assertSame(60, $this->available('20:00', '21:00'));
        $this->travelTo(now()->setTime(21, 0));
        $this->assertSame(160, $this->available('21:00', '22:00'));
        $this->assertSame(100, ResourceUsageManagement::inventory($this->equipment)['available']);
        $next = $this->booking(['start_time' => '21:00', 'end_time' => '22:00', 'quantity' => 140]);
        $this->actingAs($this->admin)->postJson(route('resource-usage.store', $next), ['action' => 'release', 'quantity' => 140, 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $record($first, 'return', 60);
        $record($next, 'release', 140);
        $this->assertSame(20, ResourceUsageManagement::inventory($this->equipment)['available']);
    }

    public function test_official_use_conflicts_include_both_buffers_and_resolve_at_exclusive_boundary(): void
    {
        $booking = $this->booking();
        $data = ['facility_id' => $this->equipment->id, 'date' => '2026-10-23', 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 70, 'purpose' => 'Barangay assembly'];
        $id = $this->actingAs($this->admin)->postJson(route('official-uses.store'), $data)->assertCreated()->json('id');
        $this->assertDatabaseHas('official_use_conflicts', ['reservation_id' => $booking->id, 'resolution' => 'awaiting_user_decision']);
        $this->assertSame(90, $this->available('21:00', '22:00'));
        $this->patchJson(route('official-uses.update', $id), array_replace($data, ['start_time' => '21:00', 'end_time' => '22:00']))->assertOk();
        $this->assertDatabaseHas('official_use_conflicts', ['reservation_id' => $booking->id, 'resolution' => 'resolved']);
        $this->assertSame(160, $this->available('23:00', '00:00'));
    }

    public function test_facility_calendar_does_not_include_the_next_days_equipment_buffer_window(): void
    {
        $this->equipment->update(['category' => 'Facility', 'capacity' => 160]);
        $this->booking(['category' => 'Facility', 'reservation_date' => '2026-10-24', 'start_time' => '00:00', 'end_time' => '01:00', 'quantity' => null]);
        $days = ReservationAvailability::monthCalendar($this->equipment->id, now()->startOfMonth(), 'Taft');
        $this->assertSame('available', $days->first(fn ($day) => $day['date']->day === 23)['status']);
    }
}
