<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Services\ResourceUsageManagement;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EquipmentAvailabilitySummaryTest extends TestCase
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
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(12, 0));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $this->equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'summary-chairs', 'name' => 'Plastic Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 160, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_replace(['user_id' => $this->resident->id, 'facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'facility_slug' => $this->equipment->slug, 'facility_name' => $this->equipment->name, 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-10-25', 'start_time' => '13:00', 'end_time' => '15:00', 'purpose' => 'Meeting', 'quantity' => 80, 'attendees' => null, 'status' => 'pending', 'hourly_rate_snapshot' => 100], $overrides));
    }

    private function summary(Reservation $reservation)
    {
        return $this->actingAs($this->admin)->getJson(route('reservations.equipment-availability', $reservation));
    }

    private function accept(Reservation $reservation, array $extra = [])
    {
        return $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), ['total_payment' => 200, 'payment_confirmed' => 1] + $extra);
    }

    private function usage(Reservation $reservation, string $action, int $quantity): void
    {
        app(ResourceUsageManagement::class)->record($this->admin, $reservation, ['action' => $action, 'quantity' => $quantity, 'request_key' => Str::uuid()->toString(), 'confirmed' => 1]);
    }

    public function test_summary_uses_real_scheduled_stock_and_partial_return_balances(): void
    {
        $this->booking(['status' => 'accepted', 'quantity' => 60]);
        $loan = $this->booking(['status' => 'accepted', 'reservation_date' => '2026-10-21', 'quantity' => 60]);
        $this->usage($loan, 'release', 60);
        $this->usage($loan, 'return', 40);
        $pending = $this->booking();
        $this->summary($pending)->assertOk()->assertJsonPath('summary.total', 160)->assertJsonPath('summary.allocated', 60)
            ->assertJsonPath('summary.scheduled_available', 100)->assertJsonPath('summary.requested', 80)
            ->assertJsonPath('summary.scheduled_remaining', 20)->assertJsonPath('summary.borrowed', 20)
            ->assertJsonPath('summary.physical_available', 140)->assertJsonPath('summary.overdue', 20)
            ->assertJsonPath('summary.can_accept', true)->assertJsonPath('summary.return_risk', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('resource_usages', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_summary_presentation_keeps_stock_values_and_unambiguous_buffer_dates(): void
    {
        foreach ([
            ['13:00', '15:00', 'Oct 25, 2026', '3:00 PM', '4:00 PM'],
            ['23:00', '01:00', 'Oct 25, 2026 11:00 PM', 'Oct 26, 2026 1:00 AM', 'Oct 26, 2026 2:00 AM'],
            ['22:00', '23:30', 'Oct 25, 2026', 'Oct 25, 2026 11:30 PM', 'Oct 26, 2026 12:30 AM'],
        ] as [$start, $end, $scheduleDate, $bufferStart, $allocationEnd]) {
            $response = $this->summary($this->booking(['start_time' => $start, 'end_time' => $end]))->assertOk();
            $html = $response->json('html');
            $document = new \DOMDocument;
            $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $this->assertStringContainsString($scheduleDate, $xpath->query('//*[contains(@class,"acceptance-schedule")]')->item(0)->textContent);
            $buffer = $xpath->query('//section[@aria-label="Equipment return buffer"]')->item(0);
            $this->assertStringContainsString('60 minutes', $buffer->textContent);
            $this->assertStringContainsString($bufferStart, $buffer->textContent);
            $this->assertStringContainsString($allocationEnd, $buffer->textContent);
            $this->assertSame(3, $xpath->query('//dl[@class="acceptance-quantities"]/div')->length);
            $details = $xpath->query('//details')->item(0);
            $this->assertFalse($details->hasAttribute('open'));
            $this->assertSame(6, $xpath->query('.//dl/div', $details)->length);
            foreach (['Requested quantity' => 'requested', 'Scheduled available' => 'scheduled_available',
                'Remaining after acceptance' => 'scheduled_remaining', 'Total Recorded Stock' => 'total',
                'Usable Stock' => 'usable', 'Damaged / Unavailable Units' => 'damaged',
                'Already Allocated' => 'allocated', 'Currently Borrowed' => 'borrowed', 'Physically Available' => 'physical_available'] as $label => $key) {
                $this->assertSame((string) $response->json('summary.'.$key), $xpath->query('//dt[text()="'.$label.'"]/following-sibling::dd')->item(0)->textContent);
            }
            if ($start === '13:00') {
                $this->assertStringNotContainsString('Oct 25', $buffer->textContent);
            }
        }
    }

    public function test_peak_allocation_handles_adjacent_partial_overlaps_and_ignores_pending(): void
    {
        $this->booking(['status' => 'accepted', 'quantity' => 30, 'end_time' => '14:00']);
        $this->booking(['status' => 'accepted', 'quantity' => 40, 'start_time' => '14:00']);
        $this->booking(['status' => 'accepted', 'quantity' => 20, 'start_time' => '13:30', 'end_time' => '14:30']);
        $this->booking(['quantity' => 160]);
        foreach (['cancelled', 'rejected'] as $status) {
            $this->booking(['status' => $status, 'quantity' => 160]);
        }
        $this->summary($this->booking())->assertOk()->assertJsonPath('summary.allocated', 90)->assertJsonPath('summary.scheduled_available', 70)->assertJsonPath('summary.borrowed', 0);
    }

    public function test_overnight_and_official_use_allocations_use_the_existing_rules(): void
    {
        $this->booking(['status' => 'accepted', 'quantity' => 40, 'start_time' => '23:00', 'end_time' => '01:00']);
        OfficialUse::create(['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'date' => '2026-10-26', 'start_time' => '00:00', 'end_time' => '02:00', 'quantity' => 50, 'purpose' => 'Official', 'status' => 'active', 'created_by' => $this->admin->id]);
        $pending = $this->booking(['start_time' => '23:00', 'end_time' => '02:00']);
        $this->summary($pending)->assertOk()->assertJsonPath('summary.allocated', 90)->assertJsonPath('summary.scheduled_available', 70)->assertJsonPath('summary.can_accept', false)->assertJsonPath('summary.indicator', 'Unavailable');
        $this->accept($pending)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stale_review_and_forged_available_quantity_cannot_overbook(): void
    {
        $pending = $this->booking(['quantity' => 100]);
        $this->summary($pending)->assertJsonPath('summary.can_accept', true);
        $this->booking(['status' => 'accepted', 'quantity' => 100]);
        $this->accept($pending, ['scheduled_available' => 9999, 'quantity' => 1, 'can_accept' => true])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(100, $pending->fresh()->quantity);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_acceptance_preserves_payment_notification_and_does_not_physically_release(): void
    {
        $first = $this->booking(['quantity' => 80]);
        $second = $this->booking(['quantity' => 100]);
        $this->accept($first)->assertOk();
        $this->summary($second)->assertJsonPath('summary.scheduled_available', 80)->assertJsonPath('summary.indicator', 'Insufficient Quantity')->assertJsonPath('summary.physical_available', 160);
        $this->assertDatabaseHas('payments', ['reservation_id' => $first->id, 'amount' => 200, 'payment_status' => 'paid']);
        $this->assertDatabaseCount('resource_usages', 0);
        Notification::assertSentTo($this->resident, ReservationActivity::class, fn ($notification) => $notification->event === 'accepted');
        $this->accept($first)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_returns_change_physical_balance_but_not_schedule(): void
    {
        $loan = $this->booking(['status' => 'accepted', 'quantity' => 60]);
        $pending = $this->booking();
        $this->usage($loan, 'release', 60);
        $this->summary($pending)->assertJsonPath('summary.allocated', 60)->assertJsonPath('summary.physical_available', 100);
        $this->usage($loan, 'return', 20);
        $this->summary($pending)->assertJsonPath('summary.allocated', 60)->assertJsonPath('summary.physical_available', 120)->assertJsonPath('summary.borrowed', 40);
        $this->usage($loan, 'return', 40);
        $this->summary($pending)->assertJsonPath('summary.allocated', 60)->assertJsonPath('summary.physical_available', 160);
    }

    public function test_future_booking_with_return_risk_is_advisory_and_cancelled_loans_still_count(): void
    {
        $loan = $this->booking(['status' => 'accepted', 'reservation_date' => '2026-10-23', 'quantity' => 150]);
        $this->usage($loan, 'release', 150);
        $loan->update(['status' => 'cancelled']);
        $pending = $this->booking();
        $this->summary($pending)->assertJsonPath('summary.can_accept', true)->assertJsonPath('summary.return_risk', true)->assertJsonPath('summary.overdue', 0)->assertJsonPath('summary.physical_available', 10);
        $this->accept($pending)->assertOk();
        $this->assertSame(150, $loan->fresh()->resourceUsage->outstanding());
    }

    public function test_unknown_stock_unavailable_equipment_and_nonpending_requests_are_blocked(): void
    {
        $pending = $this->booking();
        $this->equipment->update(['total_quantity' => null]);
        $this->summary($pending)->assertJsonPath('summary.can_accept', false)->assertJsonPath('summary.total', null);
        $this->accept($pending)->assertUnprocessable();
        $this->equipment->update(['total_quantity' => 160, 'status' => 'Unavailable']);
        $this->summary($pending)->assertJsonPath('summary.indicator', 'Unavailable');
        $this->accept($pending)->assertUnprocessable();
        $this->equipment->update(['status' => 'Available']);
        $pending->update(['status' => 'cancelled']);
        $this->summary($pending)->assertJsonPath('summary.can_accept', false);
    }

    public function test_legacy_hold_remains_explicit_and_does_not_invent_a_quantity(): void
    {
        $pending = $this->booking(['quantity' => null]);
        $this->summary($pending)->assertJsonPath('summary.requested', null)->assertJsonPath('summary.scheduled_remaining', 0)->assertJsonPath('summary.can_accept', true);
        $this->accept($pending)->assertOk();
        $this->assertNull($pending->fresh()->quantity);
    }

    public function test_barangay_scope_and_super_admin_read_only_are_enforced(): void
    {
        $pending = $this->booking();
        $other = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        foreach ([$other, $this->resident] as $actor) {
            $this->actingAs($actor)->getJson(route('reservations.equipment-availability', $pending))->assertForbidden();
            $this->postJson(route('reservations.accept', $pending), ['total_payment' => 200, 'payment_confirmed' => 1])->assertForbidden();
        }
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->getJson(route('reservations.equipment-availability', $pending))->assertOk();
        $this->postJson(route('reservations.accept', $pending), ['total_payment' => 200, 'payment_confirmed' => 1])->assertForbidden();
        $this->get(route('reservations.index'))->assertOk()->assertDontSee('data-confirm-payment ', false)->assertSee('data-equipment-availability-url', false);
        $this->summary($pending)->assertOk(); // The resource owner can review a Washington resident's booking.
    }

    public function test_facility_acceptance_remains_unchanged_without_equipment_summary(): void
    {
        $this->equipment->update(['category' => 'Facility', 'capacity' => 100]);
        $pending = $this->booking(['category' => 'Facility', 'quantity' => null, 'attendees' => 20]);
        $this->actingAs($this->admin)->get(route('reservations.index'))->assertOk()->assertDontSee('data-equipment-availability-url', false);
        $this->getJson(route('reservations.equipment-availability', $pending))->assertNotFound();
        $this->accept($pending)->assertOk();
    }
}
