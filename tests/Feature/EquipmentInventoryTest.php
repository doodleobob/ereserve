<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ResourceUsageManagement;
use App\Support\ReservationAvailability;
use App\Support\ReservationPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EquipmentInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Facility $equipment;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->equipment = Facility::create(['barangay' => 'Taft', 'slug' => 'inventory-chairs', 'name' => 'Inventory Chairs', 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 160, 'location' => 'Taft', 'status' => 'Available']);
    }

    private function adjust(string $type, int $quantity, array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson(route('equipment.inventory.store', $this->equipment), array_replace([
            'adjustment_type' => $type, 'quantity' => $quantity, 'reason' => 'Verified physical count', 'confirmed' => 1, 'request_key' => (string) Str::uuid(),
        ], $overrides));
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_replace(['user_id' => $this->admin->id, 'facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'facility_slug' => $this->equipment->slug, 'facility_name' => $this->equipment->name, 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-10-23', 'start_time' => '13:00', 'end_time' => '14:00', 'purpose' => 'Meeting', 'quantity' => 100, 'status' => 'accepted'], $overrides));
    }

    private function usage(Reservation $reservation, string $action, int $quantity): void
    {
        app(ResourceUsageManagement::class)->record($this->admin, $reservation, ['action' => $action, 'quantity' => $quantity, 'confirmed' => 1, 'request_key' => (string) Str::uuid()]);
    }

    public function test_add_reduce_damage_and_repair_preserve_audit_balances(): void
    {
        $this->adjust('add', 20)->assertOk();
        $this->adjust('reduce', 10)->assertOk();
        $this->adjust('damage', 30)->assertOk();
        $this->adjust('repair', 5)->assertOk();
        $stock = ResourceUsageManagement::inventory($this->equipment->fresh());
        $this->assertSame(170, $stock['total']);
        $this->assertSame(145, $stock['usable']);
        $this->assertSame(145, $stock['available']);
        $this->assertSame(25, $stock['damaged']);
        $this->assertSame(145, ReservationAvailability::availableQuantity($this->equipment->fresh(), new ReservationPeriod('2026-10-24', '10:00', '11:00')));
        $this->assertDatabaseCount('equipment_inventory_adjustments', 4);
        $this->assertDatabaseHas('equipment_inventory_adjustments', ['facility_id' => $this->equipment->id, 'barangay' => 'Taft', 'adjustment_type' => 'damage', 'quantity' => 30, 'total_before' => 170, 'total_after' => 170, 'damaged_before' => 0, 'damaged_after' => 30, 'performed_by' => $this->admin->id]);
    }

    public function test_invalid_adjustments_and_retries_do_not_change_stock(): void
    {
        foreach ([['add', 0], ['reduce', 161], ['damage', 161], ['repair', 1]] as [$type, $quantity]) {
            $this->adjust($type, $quantity)->assertUnprocessable();
        }
        $this->adjust('add', 1, ['reason' => ' '])->assertUnprocessable();
        $this->adjust('add', 1, ['confirmed' => 0])->assertUnprocessable();
        $this->adjust('add', 4294967295)->assertUnprocessable();
        $key = (string) Str::uuid();
        $this->adjust('add', 10, ['request_key' => $key])->assertOk();
        $this->adjust('add', 10, ['request_key' => $key])->assertOk();
        $this->adjust('add', 20, ['request_key' => $key])->assertUnprocessable();
        $this->assertSame(170, $this->equipment->fresh()->total_quantity);
        $this->assertDatabaseCount('equipment_inventory_adjustments', 1);
    }

    public function test_partial_handover_returns_and_overdue_stock_reuse_usage_records(): void
    {
        $booking = $this->booking();
        $this->usage($booking, 'release', 60);
        $this->usage($booking, 'release', 40);
        $this->usage($booking, 'return', 30);
        $this->adjust('damage', 20)->assertOk();
        $this->travelTo(now()->setTime(16, 0));
        $stock = ResourceUsageManagement::inventory($this->equipment->fresh());
        $this->assertSame(70, $stock['outstanding']);
        $this->assertSame(70, $stock['overdue']);
        $this->assertSame(70, $stock['available']);
        $this->adjust('reduce', 71)->assertUnprocessable();
        $this->adjust('damage', 71)->assertUnprocessable();
        $this->usage($booking, 'return', 70);
        $this->assertSame(140, ResourceUsageManagement::inventory($this->equipment->fresh())['available']);
        $this->assertDatabaseCount('resource_usages', 1);
        $this->assertDatabaseCount('resource_usage_events', 4);
        $this->assertSame(160, $this->equipment->fresh()->total_quantity);
    }

    public function test_stock_changes_protect_accepted_buffers_and_damaged_stock_cannot_be_released(): void
    {
        $booking = $this->booking();
        $this->travelTo(now()->setTime(14, 30));
        $this->adjust('reduce', 61)->assertUnprocessable();
        $this->adjust('damage', 61)->assertUnprocessable();
        $this->travelTo(now()->setTime(15, 0));
        $this->adjust('damage', 100)->assertOk();
        $future = $this->booking(['reservation_date' => '2026-10-24', 'quantity' => 80, 'status' => 'pending']);
        $this->actingAs($this->admin)->postJson(route('reservations.accept', $future), ['total_payment' => 0, 'payment_confirmed' => 1])->assertUnprocessable();
        $this->postJson(route('resource-usage.store', $booking), ['action' => 'release', 'quantity' => 100, 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_barangay_and_role_permissions_cover_tab_history_and_direct_writes(): void
    {
        $this->adjust('add', 1)->assertOk();
        $payload = ['adjustment_type' => 'add', 'quantity' => 1, 'reason' => 'Test', 'confirmed' => 1, 'request_key' => (string) Str::uuid()];
        $other = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($other)->postJson(route('equipment.inventory.store', $this->equipment), $payload)->assertForbidden();
        $this->get(route('equipment', ['tab' => 'inventory']))->assertOk()->assertDontSee('Inventory Chairs');
        $this->get(route('equipment', ['tab' => 'inventory', 'history' => $this->equipment->id]))->assertNotFound();
        $resident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $this->actingAs($resident)->get(route('equipment', ['tab' => 'inventory']))->assertForbidden();
        $this->postJson(route('equipment.inventory.store', $this->equipment), $payload)->assertForbidden();
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->get(route('equipment', ['tab' => 'inventory']))->assertOk()->assertSee('Inventory Chairs')->assertSee('Adjust Stock');
        $this->postJson(route('equipment.inventory.store', $this->equipment), $payload)->assertOk();
        $data = $this->equipment->fresh()->only(['name', 'category', 'description', 'location', 'status']);
        $this->patchJson(route('facilities.update', $this->equipment->slug), $data + ['total_quantity' => 180, 'inventory_reason' => 'Verified correction'])->assertOk();
    }

    public function test_equipment_form_edits_require_reason_and_audit_unknown_stock_verification(): void
    {
        $this->equipment->update(['total_quantity' => null]);
        $this->adjust('add', 10)->assertUnprocessable();
        $data = $this->equipment->only(['name', 'category', 'description', 'location', 'status']);
        $url = route('facilities.update', $this->equipment->slug);
        $this->actingAs($this->admin)->patchJson($url, $data + ['total_quantity' => 80])->assertUnprocessable();
        $this->patchJson($url, $data + ['total_quantity' => 80, 'inventory_reason' => 'Physical count verified'])->assertOk();
        $this->assertDatabaseHas('equipment_inventory_adjustments', ['adjustment_type' => 'verify', 'total_before' => null, 'total_after' => 80]);
        $this->patchJson($url, $data + ['total_quantity' => 80])->assertOk();
        $this->assertDatabaseCount('equipment_inventory_adjustments', 1);
        $this->deleteJson(route('facilities.destroy', $this->equipment->slug))->assertUnprocessable();
    }

    public function test_zero_stock_and_deactivated_equipment_are_safe(): void
    {
        $this->equipment->update(['status' => 'Unavailable']);
        $this->adjust('reduce', 160)->assertOk();
        $this->assertSame(0, $this->equipment->fresh()->total_quantity);
        $this->actingAs($this->admin)->get(route('equipment', ['tab' => 'inventory']))->assertOk()->assertSee('Unavailable');
        $this->adjust('add', 20)->assertOk();
        $this->assertSame('Unavailable', $this->equipment->fresh()->status);
    }

    public function test_adjustment_history_is_immutable(): void
    {
        $this->adjust('add', 1)->assertOk();
        $this->expectException(QueryException::class);
        DB::table('equipment_inventory_adjustments')->update(['quantity' => 999]);
    }

    public function test_disposing_damaged_units_preserves_usable_stock_without_inventing_a_repair(): void
    {
        $this->adjust('damage', 20)->assertOk();
        $this->adjust('dispose', 21)->assertUnprocessable();
        $this->adjust('dispose', 10)->assertOk();
        $stock = ResourceUsageManagement::inventory($this->equipment->fresh());
        $this->assertSame(150, $stock['total']);
        $this->assertSame(10, $stock['damaged']);
        $this->assertSame(140, $stock['usable']);
        $this->assertDatabaseHas('equipment_inventory_adjustments', ['adjustment_type' => 'dispose', 'total_before' => 160, 'total_after' => 150, 'damaged_before' => 20, 'damaged_after' => 10]);
    }

    public function test_inventory_search_history_and_usage_filter_render_and_can_export_browser_fixture(): void
    {
        $this->adjust('damage', 5)->assertOk();
        $booking = $this->booking();
        $this->usage($booking, 'release', 30);
        $response = $this->actingAs($this->admin)->get(route('equipment', ['tab' => 'inventory', 'history' => $this->equipment->id]))->assertOk()
            ->assertSee('Inventory History')->assertSee('Adjust Stock')->assertSee('Verified physical count');
        $this->get(route('equipment', ['tab' => 'inventory', 'search' => 'missing']))->assertOk()->assertDontSee('Inventory Chairs');
        $this->get(route('resource-usage.index', ['resource_id' => $this->equipment->id, 'outstanding' => 1]))->assertOk()->assertViewHas('records', fn ($records) => $records->count() === 1);
        $this->get(route('resource-usage.index', ['resource_id' => 999999, 'outstanding' => 1]))->assertOk()->assertViewHas('records', fn ($records) => $records->count() === 0);
        if ($directory = getenv('INVENTORY_UI_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/admin.html', $response->getContent());
            $previews = [];
            foreach (['add', 'reduce', 'damage', 'repair', 'dispose'] as $type) {
                $previews[$type] = $this->getJson(route('equipment.inventory.preview', ['facility' => $this->equipment, 'adjustment_type' => $type, 'quantity' => 5]))->assertOk()->json();
            }
            file_put_contents($directory.'/previews.json', json_encode($previews, JSON_THROW_ON_ERROR));
            $super = User::factory()->create(['role' => 'super_admin']);
            file_put_contents($directory.'/super-admin.html', $this->actingAs($super)->get(route('equipment', ['tab' => 'inventory']))->assertOk()->getContent());
        }
    }

    public function test_inventory_and_history_paginate_independently_and_keep_barangay_scope(): void
    {
        foreach (range(1, 12) as $number) {
            Facility::create(['barangay' => 'Taft', 'slug' => 'extra-'.$number, 'name' => 'Extra '.$number, 'category' => 'Equipment', 'description' => 'Chairs', 'total_quantity' => 10, 'location' => 'Taft', 'status' => 'Available']);
        }
        foreach (range(1, 11) as $number) {
            $this->adjust('add', 1, ['reason' => 'Delivery '.$number])->assertOk();
        }
        $url = route('equipment', ['tab' => 'inventory', 'history' => $this->equipment->id, 'page' => 2, 'history_page' => 2]);
        $response = $this->actingAs($this->admin)->get($url)->assertOk();
        $this->assertSame(13, $response->viewData('inventoryItems')->total());
        $this->assertSame(3, $response->viewData('inventoryItems')->count());
        $this->assertSame(11, $response->viewData('inventoryHistory')->total());
        $this->assertSame(1, $response->viewData('inventoryHistory')->count());
        $this->get(route('equipment', ['tab' => 'inventory', 'search' => 'Inventory Chairs']))->assertOk()
            ->assertViewHas('inventoryItems', fn ($items) => $items->total() === 1);
    }

    public static function adjustmentPreviews(): array
    {
        return [
            'add' => ['add', 170, 150, 20],
            'reduce' => ['reduce', 150, 130, 20],
            'damage' => ['damage', 160, 130, 30],
            'repair' => ['repair', 160, 150, 10],
            'dispose' => ['dispose', 150, 140, 10],
        ];
    }

    #[DataProvider('adjustmentPreviews')]
    public function test_preview_matches_saving_without_writing_stock_history_or_usage(string $type, int $total, int $usable, int $damaged): void
    {
        $this->adjust('damage', 20)->assertOk();
        $booking = $this->booking();
        $this->usage($booking, 'release', 30);
        $before = $this->equipment->fresh()->getAttributes();
        $history = DB::table('equipment_inventory_adjustments')->get()->toJson();
        $usage = DB::table('resource_usages')->get()->toJson();
        $this->getJson(route('equipment.inventory.preview', ['facility' => $this->equipment, 'adjustment_type' => $type, 'quantity' => 10]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['before' => ['total' => 160, 'usable' => 140, 'damaged' => 20], 'after' => compact('total', 'usable', 'damaged')]);
        $this->assertSame($before, $this->equipment->fresh()->getAttributes());
        $this->assertSame($history, DB::table('equipment_inventory_adjustments')->get()->toJson());
        $this->assertSame($usage, DB::table('resource_usages')->get()->toJson());
        $this->adjust($type, 10)->assertOk();
        $stock = ResourceUsageManagement::inventory($this->equipment->fresh());
        foreach (compact('total', 'usable', 'damaged') as $key => $value) {
            $this->assertSame($value, $stock[$key]);
        }
        $this->assertSame(30, $stock['outstanding']);
        $this->assertDatabaseCount('equipment_inventory_adjustments', 2);
        $this->assertDatabaseHas('equipment_inventory_adjustments', ['adjustment_type' => 'damage', 'total_after' => 160, 'damaged_after' => 20]);
    }

    public function test_preview_and_save_reject_invalid_quantities_and_protected_allocations_under_quantity(): void
    {
        $this->actingAs($this->admin);
        $url = route('equipment.inventory.preview', $this->equipment);
        foreach ([null, '', 0, -1, 1.5, 4294967296] as $quantity) {
            $this->getJson($url.'?'.http_build_query(['adjustment_type' => 'add', 'quantity' => $quantity]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        foreach ([['reduce', 161], ['damage', 161], ['repair', 1], ['dispose', 1], ['add', 4294967295]] as [$type, $quantity]) {
            $this->getJson($url.'?'.http_build_query(['adjustment_type' => $type, 'quantity' => $quantity]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
            $this->adjust($type, $quantity)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->booking();
        $this->travelTo(now()->setTime(14, 30));
        $this->getJson($url.'?adjustment_type=reduce&quantity=61')->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->adjust('reduce', 61)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->travelTo(now()->setTime(15, 0));
        $this->getJson($url.'?adjustment_type=reduce&quantity=61')->assertOk();
        $this->assertDatabaseCount('equipment_inventory_adjustments', 0);
        $this->assertSame(160, $this->equipment->fresh()->total_quantity);
    }

    public function test_preview_preserves_authorization_and_rejects_unknown_stock_and_actions(): void
    {
        $url = route('equipment.inventory.preview', ['facility' => $this->equipment, 'adjustment_type' => 'add', 'quantity' => 1]);
        $this->getJson($url)->assertUnauthorized();
        foreach ([['role' => 'user', 'barangay' => 'Taft'], ['role' => 'admin', 'barangay' => 'Washington']] as $attributes) {
            $this->actingAs(User::factory()->create($attributes))->getJson($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barangay' => 'Taft', 'is_active' => false]))->getJson($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->getJson($url)->assertOk();
        $this->actingAs($this->admin)->getJson(route('equipment.inventory.preview', ['facility' => $this->equipment, 'adjustment_type' => 'invent', 'quantity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('adjustment_type');
        $this->equipment->update(['total_quantity' => null]);
        $this->getJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('equipment_inventory_adjustments', 0);
    }

    public function test_save_rechecks_stock_after_preview_and_requires_reason_and_confirmation(): void
    {
        $this->actingAs($this->admin)->getJson(route('equipment.inventory.preview', ['facility' => $this->equipment, 'adjustment_type' => 'reduce', 'quantity' => 100]))->assertOk();
        $this->usage($this->booking(), 'release', 80);
        $this->adjust('reduce', 100)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->adjust('add', 1, ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->adjust('add', 1, ['reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->adjust('add', 1, ['confirmed' => 0])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('equipment_inventory_adjustments', 0);
    }
}
