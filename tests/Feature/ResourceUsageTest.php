<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\ResourceUsage;
use App\Models\ResourceUsageEvent;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Services\ResourceUsageManagement;
use App\Support\ReservationAvailability;
use App\Support\ResourceUsageReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResourceUsageTest extends TestCase
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
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(13, 0));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $this->equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'usage-chairs', 'name' => 'Usage Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 160, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
    }

    private function booking(array $overrides = [], ?Facility $resource = null): Reservation
    {
        $resource ??= $this->equipment;

        return Reservation::create(array_replace(['user_id' => $this->resident->id, 'facility_id' => $resource->id,
            'barangay' => 'Taft', 'facility_slug' => $resource->slug, 'facility_name' => $resource->name, 'category' => $resource->category,
            'location' => 'Taft', 'reservation_date' => '2026-10-22', 'start_time' => '13:00', 'end_time' => '15:00',
            'purpose' => 'Usage test', 'quantity' => $resource->isEquipment() ? 60 : null, 'attendees' => $resource->isEquipment() ? null : 20,
            'status' => 'accepted', 'total_payment' => 200, 'hourly_rate_snapshot' => 100], $overrides));
    }

    private function court(): Facility
    {
        return Facility::create(['barangay' => 'Taft', 'slug' => 'usage-court', 'name' => 'Usage Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 200, 'location' => 'Taft', 'status' => 'Available']);
    }

    private function action(Reservation $reservation, string $action, ?int $quantity = null, ?string $key = null, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->admin)->postJson(route('resource-usage.store', $reservation), [
            'action' => $action, 'quantity' => $quantity, 'request_key' => $key ?? (string) Str::uuid(), 'confirmed' => 1,
        ]);
    }

    public function test_accepted_reservations_are_not_started_by_time_or_read_requests(): void
    {
        $this->booking();
        $this->booking([], $this->court());
        $this->travelTo(now()->setTime(14, 0));
        $this->actingAs($this->admin)->get(route('resource-usage.index'))->assertOk()->assertSee('Not Released')->assertSee('Not Started');
        $this->assertDatabaseCount('resource_usages', 0);
        $this->assertSame(160, ResourceUsageManagement::inventory($this->equipment)['available']);
    }

    public function test_facility_transitions_are_manual_idempotent_and_preserve_reservation(): void
    {
        $reservation = $this->booking([], $this->court());
        $before = $reservation->fresh()->getAttributes();
        $this->action($reservation, 'check_out')->assertUnprocessable();
        $key = (string) Str::uuid();
        $this->action($reservation, 'check_in', key: $key)->assertOk();
        $this->action($reservation, 'check_in', key: $key)->assertOk();
        $this->action($reservation, 'check_in')->assertUnprocessable();
        $usage = $reservation->fresh()->resourceUsage;
        $this->assertSame('in_use', $usage->status());
        $this->assertSame($this->admin->id, $usage->events->first()->performed_by);
        $this->action($reservation, 'check_out')->assertOk();
        $this->action($reservation, 'check_out')->assertUnprocessable();
        $this->assertSame('completed', $usage->fresh()->status());
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertDatabaseCount('resource_usage_events', 2);
    }

    public function test_facility_cannot_be_occupied_by_two_reservations_even_after_scheduled_end(): void
    {
        $court = $this->court();
        $first = $this->booking([], $court);
        $second = $this->booking(['start_time' => '15:00', 'end_time' => '17:00'], $court);
        $this->action($first, 'check_in')->assertOk();
        $this->travelTo(now()->setTime(16, 0));
        $this->action($second, 'check_in')->assertUnprocessable();
        $this->action($first, 'check_out')->assertOk();
        $this->action($second, 'check_in')->assertOk();
    }

    public function test_release_and_multiple_partial_returns_preserve_scheduled_allocations_and_payment(): void
    {
        $reservation = $this->booking();
        $payment = $reservation->payment()->create(['amount' => 200, 'payment_status' => 'paid']);
        $before = $reservation->fresh()->getAttributes();
        $paymentBefore = $payment->fresh()->getAttributes();
        $this->assertSame(100, ReservationAvailability::availableQuantity($this->equipment, $reservation->period()));
        $this->action($reservation, 'release', 60)->assertOk();
        $this->assertSame(100, ResourceUsageManagement::inventory($this->equipment)['available']);
        $this->action($reservation, 'return', 20)->assertOk();
        $usage = $reservation->fresh()->resourceUsage;
        $this->assertSame('partially_returned', $usage->status());
        $this->assertSame(40, $usage->outstanding());
        $this->assertSame(120, ResourceUsageManagement::inventory($this->equipment)['available']);
        $this->action($reservation, 'return', 15)->assertOk();
        $this->action($reservation, 'return', 25)->assertOk();
        $this->assertSame('returned', $usage->fresh()->status());
        $this->assertSame(160, ResourceUsageManagement::inventory($this->equipment)['available']);
        $this->assertSame(100, ReservationAvailability::availableQuantity($this->equipment, $reservation->period()));
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('resource_usage_events', 4);
    }

    public function test_partial_releases_never_close_usage_with_releases_pending(): void
    {
        $reservation = $this->booking();
        $this->action($reservation, 'release', 20)->assertOk();
        $this->action($reservation, 'return', 20)->assertOk();
        $usage = $reservation->fresh()->resourceUsage;
        $this->assertSame('partially_returned', $usage->status());
        $this->assertNull($usage->actual_ended_at);
        $this->action($reservation, 'release', 41)->assertUnprocessable();
        $this->action($reservation, 'release', 40)->assertOk();
        $this->action($reservation, 'release', 1)->assertUnprocessable();
        $this->action($reservation, 'return', 40)->assertOk();
        $this->assertSame('returned', $usage->fresh()->status());
        $this->assertDatabaseCount('resource_usage_events', 4);
    }

    public function test_past_loans_block_new_releases_until_physical_returns(): void
    {
        $past = $this->booking(['reservation_date' => '2026-10-21', 'quantity' => 130]);
        $today = $this->booking();
        $this->action($past, 'release', 130)->assertOk();
        $this->action($today, 'release', 60)->assertUnprocessable();
        $this->action($past, 'return', 30)->assertOk();
        $this->action($today, 'release', 60)->assertOk();
        $this->assertSame(0, ResourceUsageManagement::inventory($this->equipment)['available']);
    }

    public function test_duplicate_retries_and_changed_payloads_cannot_double_return(): void
    {
        $reservation = $this->booking();
        $this->action($reservation, 'release', 60)->assertOk();
        $key = (string) Str::uuid();
        $this->action($reservation, 'return', 20, $key)->assertOk();
        $this->action($reservation, 'return', 20, $key)->assertOk();
        $this->action($reservation, 'return', 10, $key)->assertUnprocessable();
        $this->action($reservation, 'return', 41)->assertUnprocessable();
        $this->assertSame(40, $reservation->fresh()->resourceUsage->outstanding());
        $this->assertDatabaseCount('resource_usage_events', 2);
    }

    public function test_roles_and_resource_barangay_are_enforced_for_reads_exports_and_all_actions(): void
    {
        $reservation = $this->booking();
        $other = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $super = User::factory()->create(['role' => 'super_admin']);
        foreach ([$other, $super, $this->resident] as $actor) {
            foreach (['release', 'return', 'check_in', 'check_out'] as $action) {
                $this->action($reservation, $action, str_contains($action, 'check') ? null : 1, actor: $actor)->assertForbidden();
            }
        }
        $this->actingAs($other)->get(route('resource-usage.index'))->assertOk()->assertDontSee('Usage Chairs');
        $this->actingAs($super)->get(route('resource-usage.index'))->assertOk()->assertSee('Usage Chairs')->assertSee('All Barangays')->assertDontSee('name="request_key"', false);
        $this->actingAs($this->resident)->get(route('resource-usage.index'))->assertForbidden();
        $this->get(route('resource-usage.export', ['format' => 'xlsx']))->assertForbidden();
        $this->actingAs($other)->get(route('resource-usage.export', ['format' => 'pdf']))->assertOk();
    }

    public function test_cross_barangay_residents_are_managed_by_resource_owner(): void
    {
        $this->resident->update(['barangay' => 'Washington']);
        $reservation = $this->booking(['barangay' => 'Washington']);
        $this->action($reservation, 'release', 1)->assertOk();
        $this->actingAs($this->admin)->get(route('resource-usage.index'))->assertOk()->assertSee('Usage Chairs');
    }

    public function test_cancelled_and_conflicted_loans_can_be_returned_but_not_released(): void
    {
        $reservation = $this->booking();
        $this->action($reservation, 'release', 30)->assertOk();
        $use = OfficialUse::create(['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'date' => '2026-10-22', 'start_time' => '13:00', 'end_time' => '16:00', 'quantity' => 160, 'purpose' => 'Official', 'status' => 'conflict', 'created_by' => $this->admin->id]);
        $reservation->officialUseConflicts()->create(['official_use_id' => $use->id, 'resolution' => 'awaiting_user_decision']);
        $this->action($reservation, 'release', 10)->assertUnprocessable();
        $this->action($reservation, 'return', 10)->assertOk();
        $reservation->update(['status' => 'cancelled']);
        $this->action($reservation, 'release', 10)->assertUnprocessable();
        $this->action($reservation, 'return', 20)->assertOk();
        $this->actingAs($this->admin)->get(route('resource-usage.index'))->assertOk()->assertSee('Usage Chairs');
    }

    public function test_cancelled_facility_can_check_out(): void
    {
        $reservation = $this->booking([], $this->court());
        $this->action($reservation, 'check_in')->assertOk();
        $reservation->update(['status' => 'cancelled']);
        $this->action($reservation, 'check_out')->assertOk();
    }

    public function test_direct_official_use_conflict_blocks_without_conflict_row(): void
    {
        $reservation = $this->booking();
        OfficialUse::create(['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'date' => '2026-10-22', 'start_time' => '13:00', 'end_time' => '16:00', 'quantity' => 110, 'purpose' => 'Official', 'status' => 'active', 'created_by' => $this->admin->id]);
        $this->action($reservation, 'release', 1)->assertUnprocessable();
        $this->assertDatabaseCount('resource_usages', 0);
    }

    public function test_quantities_confirmation_type_and_keys_are_validated(): void
    {
        $reservation = $this->booking();
        foreach ([0, -1, 1.5, '10 chairs', null, 4294967296] as $quantity) {
            $this->actingAs($this->admin)->postJson(route('resource-usage.store', $reservation), ['action' => 'release', 'quantity' => $quantity, 'confirmed' => 1, 'request_key' => Str::uuid()])->assertUnprocessable();
        }
        $this->postJson(route('resource-usage.store', $reservation), ['action' => 'release', 'quantity' => 1])->assertUnprocessable();
        $this->action($reservation, 'check_in')->assertUnprocessable();
        $this->action($this->booking([], $this->court()), 'release', 1)->assertUnprocessable();
        $this->assertDatabaseCount('resource_usages', 0);
    }

    public function test_unknown_legacy_quantities_and_nonaccepted_reservations_cannot_start(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->action($this->booking(['status' => $status]), 'release', 1)->assertUnprocessable();
        }
        $legacy = $this->booking(['quantity' => null]);
        $this->action($legacy, 'release', 1)->assertUnprocessable();
        $legacy->update(['status' => 'cancelled']);
        $this->equipment->update(['total_quantity' => null]);
        $this->action($this->booking(), 'release', 1)->assertUnprocessable();
    }

    public function test_overnight_overdue_preserves_status_and_clears_only_after_return(): void
    {
        $reservation = $this->booking(['start_time' => '23:00', 'end_time' => '01:00']);
        $this->travelTo(now()->setTime(23, 0));
        $this->action($reservation, 'release', 60)->assertOk();
        $this->travelTo(now()->addDay()->setTime(0, 30));
        $usage = $reservation->fresh()->resourceUsage;
        $this->assertFalse($usage->overdue());
        $this->travelTo(now()->setTime(1, 1));
        $this->assertTrue($usage->overdue());
        $this->assertSame('borrowed', $usage->status());
        $this->actingAs($this->admin)->get(route('resource-usage.index', ['overdue' => 1]))->assertOk()->assertSee('Usage Chairs');
        $this->action($reservation, 'return', 60)->assertOk();
        $this->assertFalse($usage->fresh()->overdue());
        $this->get(route('resource-usage.index', ['overdue' => 1]))->assertOk()->assertDontSee('Usage Chairs');
    }

    public function test_facility_overdue_only_while_checked_in(): void
    {
        $reservation = $this->booking([], $this->court());
        $this->action($reservation, 'check_in')->assertOk();
        $this->travelTo(now()->setTime(15, 1));
        $this->assertTrue($reservation->fresh()->resourceUsage->overdue());
        $this->action($reservation, 'check_out')->assertOk();
        $this->assertFalse($reservation->fresh()->resourceUsage->overdue());
    }

    public function test_filters_sorting_pagination_and_export_formats(): void
    {
        $this->booking();
        $this->booking([], $this->court());
        $this->actingAs($this->admin)->get(route('resource-usage.index', ['type' => 'Equipment', 'status' => 'not_released', 'search' => 'Chairs', 'from_date' => '2026-10-22', 'to_date' => '2026-10-22', 'per_page' => 25]))->assertOk()->assertSee('Usage Chairs')->assertDontSee('Usage Court');
        foreach (array_keys(ResourceUsageReport::columns(true)) as $sort) {
            $this->get(route('resource-usage.index', ['sort' => $sort, 'direction' => 'asc']))->assertOk();
        }
        foreach (['pdf' => '%PDF', 'xlsx' => 'PK'] as $format => $signature) {
            $response = $this->get(route('resource-usage.export', ['format' => $format]));
            $response->assertOk();
            $this->assertStringStartsWith($signature, $response->streamedContent());
        }
        $this->getJson(route('resource-usage.index', ['sort' => 'invalid']))->assertUnprocessable();
    }

    public function test_event_and_quantity_database_integrity(): void
    {
        $reservation = $this->booking();
        $this->action($reservation, 'release', 60)->assertOk();
        foreach ([
            fn () => DB::table('resource_usages')->update(['returned_quantity' => 61]),
            fn () => DB::table('resource_usages')->update(['released_quantity' => 61]),
            fn () => DB::table('resource_usage_events')->update(['quantity' => 1]),
            fn () => DB::table('resource_usage_events')->delete(),
            fn () => DB::table('reservations')->where('id', $reservation->id)->delete(),
            fn () => DB::table('users')->where('id', $this->admin->id)->delete(),
        ] as $invalidWrite) {
            try {
                $invalidWrite();
                $this->fail('Invalid database write was allowed.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(60, ResourceUsage::first()->outstanding());
        $this->assertSame(60, ResourceUsageEvent::first()->quantity);
    }

    public function test_notifications_use_existing_channel_and_failure_does_not_undo_inventory(): void
    {
        $reservation = $this->booking();
        $this->action($reservation, 'release', 60)->assertOk();
        Notification::assertSentTo($this->resident, ReservationActivity::class, fn ($notification) => $notification->event === 'usage_release');
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('Synthetic queue failure'));
        $this->action($reservation, 'return', 60)->assertOk();
        $this->assertSame(0, $reservation->fresh()->resourceUsage->outstanding());
    }

    public function test_inventory_edits_cannot_remove_outstanding_units_after_cancellation(): void
    {
        $reservation = $this->booking(['reservation_date' => '2026-10-21']);
        $this->action($reservation, 'release', 60)->assertOk();
        $reservation->update(['status' => 'cancelled']);
        $data = $this->equipment->only(['name', 'category', 'description', 'location', 'status']);
        $this->actingAs($this->admin)->patchJson(route('facilities.update', $this->equipment->slug), $data + ['total_quantity' => 40])
            ->assertUnprocessable()->assertJsonValidationErrors('total_quantity');
        $this->assertSame(160, $this->equipment->fresh()->total_quantity);
        $this->action($reservation, 'return', 20)->assertOk();
        $this->patchJson(route('facilities.update', $this->equipment->slug), $data + ['total_quantity' => 40])->assertOk();
        $this->assertSame(0, ResourceUsageManagement::inventory($this->equipment->fresh())['available']);
    }

    public function test_usage_notifications_are_stored_and_queued_on_database_connection(): void
    {
        Notification::swap(new ChannelManager($this->app));
        Bus::fake();
        $reservation = $this->booking();
        $key = (string) Str::uuid();
        $this->action($reservation, 'release', 60, $key)->assertOk();
        $this->action($reservation, 'release', 60, $key)->assertOk();
        $this->assertDatabaseCount('notifications', 1);
        Bus::assertDispatched(SendQueuedNotifications::class, function ($job) {
            return $job->connection === 'database' && $job->notification->event === 'usage_release'
                && str_contains($job->notification->toMail($this->resident)->subject, 'Equipment Released');
        });
        $this->action($reservation, 'return', 60)->assertOk();
        $this->assertDatabaseCount('notifications', 2);
        Bus::assertDispatchedTimes(SendQueuedNotifications::class, 2);
    }
}
