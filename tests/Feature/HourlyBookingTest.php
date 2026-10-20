<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationAvailability;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HourlyBookingTest extends TestCase
{
    use RefreshDatabase;

    private Facility $resource;

    private User $resident;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-20 10:15:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resource = Facility::create(['barangay' => 'Taft', 'slug' => 'hourly-court', 'name' => 'Court', 'category' => 'Facility', 'capacity' => 50, 'description' => 'Court', 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 50]);
        $this->actingAs($this->resident);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['reservation_date' => '2026-10-21', 'start_time' => '13:00', 'end_time' => '17:00', 'purpose' => 'Meeting', 'attendees' => 10], $overrides);
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_replace($this->data(), ['user_id' => $this->admin->id, 'facility_id' => $this->resource->id, 'facility_slug' => $this->resource->slug, 'facility_name' => 'Court', 'category' => $this->resource->category, 'barangay' => 'Taft', 'location' => 'Taft', 'status' => 'accepted', 'hourly_rate_snapshot' => 50, 'total_payment' => 123.45], $overrides));
    }

    public static function validPeriods(): array
    {
        return [['13:00', '14:00', 0, 1], ['13:00', '17:00', 0, 4], ['08:00', '18:00', 0, 10], ['00:00', '23:00', 0, 23], ['22:00', '02:00', 1, 4]];
    }

    #[DataProvider('validPeriods')]
    public function test_one_record_and_authoritative_price_for_consecutive_hours(string $start, string $end, int $next, int $hours): void
    {
        $data = $this->data(['start_time' => $start, 'end_time' => $end, 'end_next_day' => $next]);
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$data]))->assertOk()->assertJsonPath('duration_minutes', $hours * 60)->assertJsonPath('estimated_total', number_format($hours * 50, 2, '.', ''));
        $this->postJson(route('reservations.store', $this->resource->slug), $data)->assertCreated();
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($hours * 60, Reservation::first()->durationMinutes());
        $this->assertNull(Reservation::first()->total_payment);
    }

    public static function invalidPeriods(): array
    {
        return [
            [['start_time' => '13:15', 'end_time' => '14:15'], 'start_time'],
            [['end_time' => '17:30'], 'end_time'],
            [['end_time' => '13:00'], 'end_time'],
            [['start_time' => '22:00', 'end_time' => '02:00'], 'end_time'],
            [['end_next_day' => 1], 'end_time'],
            [['end_next_day' => 'tomorrow'], 'end_next_day'],
            [['reservation_date' => '2026-10-20', 'start_time' => '10:00'], 'start_time'],
            [['start_time' => '13:00:01'], 'start_time'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_tampered_new_schedules_are_rejected(array $override, string $field): void
    {
        $this->postJson(route('reservations.store', $this->resource->slug), $this->data($override))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_historical_minutes_block_overlap_but_allow_adjacent_hour(): void
    {
        $this->booking(['start_time' => '13:15', 'end_time' => '16:00']);
        $this->postJson(route('reservations.store', $this->resource->slug), $this->data())->assertUnprocessable()->assertJsonValidationErrors('reservation');
        $this->postJson(route('reservations.store', $this->resource->slug), $this->data(['start_time' => '16:00']))->assertCreated();
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_equipment_options_use_peak_allocation_quantity_and_existing_pricing(): void
    {
        $this->resource->update(['category' => 'Equipment', 'total_quantity' => 40]);
        $this->booking(['start_time' => '13:00', 'end_time' => '15:00', 'quantity' => 20]);
        $this->booking(['start_time' => '15:00', 'end_time' => '17:00', 'quantity' => 20]);
        $data = $this->data(['start_time' => '14:00', 'quantity' => 20]);
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$data]))->assertOk()->assertJsonPath('available_quantity', 20)->assertJsonPath('available', true)->assertJsonPath('estimated_total', '150.00');
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$data, 'quantity' => 25]))->assertOk()->assertJsonPath('available', false)->assertJsonMissing(['end_options' => ['17:00']]);
        $this->postJson(route('reservations.store', $this->resource->slug), [...$data, 'quantity' => 25])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson(route('reservations.store', $this->resource->slug), $data)->assertCreated();
        $this->assertSame('150.00', Reservation::latest('id')->first()->calculatedAmount());
    }

    public function test_overnight_preview_respects_next_day_legacy_official_use(): void
    {
        OfficialUse::create(['facility_id' => $this->resource->id, 'barangay' => 'Taft', 'date' => '2026-10-22', 'start_time' => '01:15', 'end_time' => '02:30', 'purpose' => 'Legacy', 'status' => 'active', 'created_by' => $this->admin->id]);
        $data = $this->data(['start_time' => '22:00', 'end_time' => '02:00', 'end_next_day' => 1]);
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$data]))->assertOk()->assertJsonPath('available', false)->assertJsonPath('end_options', ['00:00', '01:00']);
        $this->postJson(route('reservations.store', $this->resource->slug), $data)->assertUnprocessable();
    }

    public function test_unchanged_legacy_times_and_payment_survive_edit_but_date_changes_require_hours(): void
    {
        $reservation = $this->booking(['user_id' => $this->resident->id, 'status' => 'pending', 'start_time' => '13:15:17', 'end_time' => '15:45:23']);
        $data = $this->data(['start_time' => '13:15', 'end_time' => '15:45', 'purpose' => 'Changed purpose']);
        $this->patchJson(route('reservations.update-pending', $reservation), $data)->assertOk();
        $this->assertSame('13:15:17', $reservation->fresh()->start_time);
        $this->assertSame('15:45:23', $reservation->fresh()->end_time);
        $this->assertSame('123.45', $reservation->fresh()->total_payment);
        $this->patchJson(route('reservations.update-pending', $reservation), [...$data, 'reservation_date' => '2026-10-22'])->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $reservation->update(['status' => 'accepted']);
        $payment = $reservation->payment()->create(['amount' => '123.45', 'payment_status' => 'paid']);
        $before = $payment->fresh()->getAttributes();
        $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $reservation), ['action' => 'reschedule', ...$this->data(['start_time' => '22:00', 'end_time' => '02:00', 'end_next_day' => 1])])->assertOk();
        $this->assertSame('123.45', $reservation->fresh()->total_payment);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_official_use_hourly_policy_and_legacy_unrelated_edit_for_both_admin_roles(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $this->admin->update(['role' => $role]);
            $this->actingAs($this->admin);
            $data = ['facility_id' => $this->resource->id, 'date' => '2026-10-21', 'start_time' => '08:15', 'end_time' => '12:15', 'purpose' => 'Assembly'];
            $this->postJson(route('official-uses.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('start_time');
            $use = OfficialUse::create([...$data, 'barangay' => 'Taft', 'status' => 'active', 'created_by' => $this->admin->id]);
            $this->patchJson(route('official-uses.update', $use), [...$data, 'purpose' => 'Updated assembly'])->assertOk();
            $this->assertSame('08:15', $use->fresh()->start_time);
            $this->patchJson(route('official-uses.update', $use), [...$data, 'date' => '2026-10-22'])->assertUnprocessable();
            $use->delete();
            $this->postJson(route('official-uses.store'), [...$data, 'start_time' => '22:00', 'end_time' => '02:00', 'end_next_day' => 1])->assertCreated();
            OfficialUse::query()->delete();
        }
    }

    public function test_daytime_occupancy_does_not_hide_free_evening_hours(): void
    {
        $this->booking(['start_time' => '08:00', 'end_time' => '18:00']);
        $day = ReservationAvailability::monthCalendar($this->resource->id, Carbon::parse('2026-10-01'))->first(fn ($day) => $day['date']->day === 21);
        $this->assertSame('partial', $day['status']);
        $this->assertSame(14, $day['available_slots']);
        $this->booking(['start_time' => '00:00', 'end_time' => '08:00']);
        $this->booking(['start_time' => '18:00', 'end_time' => '00:00']);
        $day = ReservationAvailability::monthCalendar($this->resource->id, Carbon::parse('2026-10-01'))->first(fn ($day) => $day['date']->day === 21);
        $this->assertSame('full', $day['status']);
    }

    public function test_hourly_forms_render_for_all_workflows(): void
    {
        $pending = $this->booking(['user_id' => $this->resident->id, 'status' => 'pending', 'start_time' => '13:15', 'end_time' => '15:45']);
        $pages = ['facility' => route('facilities.show', ['slug' => $this->resource->slug, 'date' => '2026-10-22']), 'pending' => route('reservations.index')];
        foreach ($pages as $name => $url) {
            $this->render($name, $url);
        }
        $this->resource->update(['category' => 'Equipment', 'total_quantity' => 40]);
        $this->render('equipment', route('equipment.show', ['slug' => $this->resource->slug, 'date' => '2026-10-22']));
        $pending->update(['status' => 'accepted']);
        $this->actingAs($this->admin);
        $this->render('accepted', route('reservations.index'));
        $this->render('official', route('official-uses.index'));
    }

    public function test_legacy_pending_equipment_keeps_null_quantity_and_preview_respects_its_whole_resource_hold(): void
    {
        $this->resource->update(['category' => 'Equipment', 'total_quantity' => 40]);
        $reservation = $this->booking(['status' => 'pending', 'user_id' => $this->resident->id, 'quantity' => null, 'start_time' => '13:15', 'end_time' => '15:45']);
        $data = $this->data(['start_time' => '13:15', 'end_time' => '15:45', 'quantity' => null, 'purpose' => 'Updated legacy hold']);
        $this->patchJson(route('reservations.update-pending', $reservation), $data)->assertOk();
        $this->assertNull($reservation->fresh()->quantity);
        $this->assertSame('13:15', $reservation->fresh()->start_time);
        $this->booking(['quantity' => 20]);
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, 'reservation_id' => $reservation->id, ...$data]))
            ->assertOk()->assertJsonPath('available', false)->assertJsonPath('available_quantity', 20);
    }

    public function test_unverified_stock_cannot_be_offered_by_the_hourly_preview(): void
    {
        $this->resource->update(['category' => 'Equipment', 'total_quantity' => null]);
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$this->data()]))
            ->assertOk()->assertJsonPath('available', false)->assertJsonPath('start_options', [])->assertJsonPath('available_quantity', 0);
    }

    private function render(string $name, string $url): void
    {
        $html = $this->get($url)->assertOk()->assertSee('hourly-time-select')->assertSee('name="end_next_day"', false)->assertDontSee('type="time"', false)->getContent();
        if ($directory = getenv('HOURLY_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
