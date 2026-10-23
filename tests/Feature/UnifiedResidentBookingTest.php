<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UnifiedResidentBookingTest extends TestCase
{
    use RefreshDatabase;

    private Facility $resource;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-20 10:15:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $this->resource = Facility::create(['barangay' => 'Taft', 'slug' => 'unified-chairs', 'name' => 'Chairs',
            'category' => 'Equipment', 'total_quantity' => 40, 'capacity' => 50, 'description' => 'Community resource',
            'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 50]);
        $this->actingAs($this->resident);
    }

    private function page(array $query = []): string
    {
        return route(\App\Support\FacilityCatalog::detailRoute($this->resource->category), ['slug' => $this->resource->slug, ...$query]);
    }

    private function schedule(array $overrides = []): array
    {
        return array_replace(['reservation_date' => '2026-10-22', 'start_time' => '23:00',
            'end_time' => '03:00', 'end_next_day' => 1, 'quantity' => 10, 'purpose' => 'Gathering'], $overrides);
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_replace($this->schedule(), [
            'user_id' => User::factory()->create(['barangay' => 'Taft'])->id,
            'facility_id' => $this->resource->id, 'facility_slug' => $this->resource->slug,
            'facility_name' => 'Chairs', 'barangay' => 'Taft', 'category' => 'Equipment',
            'location' => 'Taft', 'status' => 'accepted', 'hourly_rate_snapshot' => 50,
        ], $overrides));
    }

    public function test_both_resources_render_one_editable_form_without_a_transfer_step(): void
    {
        foreach (['equipment', 'facility'] as $type) {
            if ($type === 'facility') {
                $this->resource->update(['category' => 'Facility']);
            }
            foreach (['2026-10-22', '2026-10-23'] as $date) {
                $html = $this->get($this->page(['date' => $date]))->assertOk()
                    ->assertDontSee('Use Selected Time')->assertDontSee('custom-time-selection')
                    ->assertSee('Booking Summary')->assertSee('Reservation Ends')->getContent();
                $document = new \DOMDocument;
                $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                $xpath = new \DOMXPath($document);
                $this->assertSame(1, $xpath->query('//form[@data-unified-booking]')->length);
                foreach (['reservation_date', 'start_time', 'end_time', 'end_next_day'] as $field) {
                    $this->assertSame(1, $xpath->query('//*[@name="'.$field.'"]')->length);
                }
                $this->assertSame($type === 'equipment' ? 1 : 0, $xpath->query('//input[@name="quantity"]')->length);
                $this->assertSame($type === 'facility' ? 1 : 0, $xpath->query('//input[@name="attendees"]')->length);
                $this->assertSame(1, $xpath->query('//form[@data-unified-booking]//button[@type="submit"][@disabled]')->length);
                if ($directory = getenv('UNIFIED_BOOKING_RENDER_DIR')) {
                    if (! is_dir($directory)) {
                        mkdir($directory, 0777, true);
                    }
                    file_put_contents($directory.'/'.$type.'-'.$date.'.html', $html);
                }
            }
        }
    }

    public function test_equipment_shortage_retains_its_quantity_error_key_for_ui_placement(): void
    {
        $this->resource->update(['total_quantity' => 360]);
        $response = $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$this->schedule(['quantity' => 500])]))
            ->assertOk()->assertJsonPath('available', false)->assertJsonPath('available_quantity', 360)
            ->assertJsonPath('errors.quantity.0', 'Only 360 units are available for the selected date and time.');
        $this->assertSame($response->json('message'), $response->json('errors.quantity.0'));
        $page = $this->page(['date' => '2026-10-22', 'start_time' => '23:00', 'end_time' => '03:00', 'end_next_day' => 1, 'quantity' => 500]);
        $this->get($page)->assertOk()->assertSee('data-validation-field="quantity"', false);
        $this->from($page)->post(route('reservations.store', $this->resource->slug), $this->schedule(['quantity' => 500]))
            ->assertSessionHasErrors('quantity')->assertSessionHasInput('quantity', 500);
        $this->get($page)->assertOk()->assertSee('value="500"', false)->assertSee('data-validation-field="quantity"', false);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_deep_links_are_validated(): void
    {
        foreach ([['date' => '2026-02-30'], ['start_time' => '25:00'], ['end_next_day' => 'yes'], ['quantity' => '1.5'], ['quantity' => ['bad']]] as $invalid) {
            $this->from(route('facilities'))->get($this->page($invalid))->assertRedirect(route('facilities'))
                ->assertSessionHasErrors(array_keys($invalid));
        }
    }

    public function test_posted_values_take_precedence_after_errors(): void
    {
        $url = $this->page(['date' => '2026-10-22', 'start_time' => '23:00', 'end_time' => '03:00', 'end_next_day' => 1]);
        $this->from($url)->post(route('reservations.store', $this->resource->slug), $this->schedule([
            'reservation_date' => '2026-10-23', 'start_time' => '14:00', 'end_time' => '18:00',
            'end_next_day' => 0, 'quantity' => 41, 'purpose' => 'Preserve my details',
        ]))->assertSessionHasErrors('quantity');
        $this->get($url)->assertOk()->assertSee('Preserve my details')->assertSee('value="41"', false)
            ->assertSee('value="14:00" selected', false)->assertSee('value="18:00" selected', false)
            ->assertSee('value="2026-10-23"', false)->assertSee('value="0" selected', false);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_invalid_availability_requests_return_json_errors(): void
    {
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$this->schedule(['quantity' => '1.5'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_malformed_posted_values_do_not_break_the_validation_redirect(): void
    {
        $url = $this->page(['date' => '2026-10-22']);
        $this->from($url)->post(route('reservations.store', $this->resource->slug), $this->schedule([
            'start_time' => ['13:00'], 'quantity' => ['4'], 'purpose' => ['Event'],
        ]))->assertRedirect($url)->assertSessionHasErrors(['start_time', 'quantity', 'purpose']);
        $this->get($url)->assertOk()->assertSee('data-unified-booking', false);
    }

    public function test_overnight_preview_uses_peak_allocation_including_official_use_and_legacy_holds(): void
    {
        $this->booking(['start_time' => '23:00', 'end_time' => '01:00', 'quantity' => 20]);
        $this->booking(['reservation_date' => '2026-10-23', 'start_time' => '00:00', 'end_time' => '02:00', 'quantity' => 10]);
        $preview = fn (array $data = []) => $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$this->schedule($data)]));
        $preview()->assertOk()->assertJsonPath('available_quantity', 10)->assertJsonPath('available', true)
            ->assertJsonPath('duration_minutes', 240)->assertJsonPath('estimated_total', '200.00')->assertJsonPath('end_date', '2026-10-23');
        $preview(['quantity' => 11])->assertOk()->assertJsonPath('available', false);
        OfficialUse::create(['facility_id' => $this->resource->id, 'barangay' => 'Taft', 'date' => '2026-10-23',
            'start_time' => '00:15', 'end_time' => '00:45', 'quantity' => 5, 'purpose' => 'Legacy official allocation', 'status' => 'active']);
        $preview(['quantity' => 5])->assertOk()->assertJsonPath('available_quantity', 5)->assertJsonPath('estimated_total', '200.00');
        $this->booking(['reservation_date' => '2026-10-23', 'start_time' => '02:15', 'end_time' => '02:30', 'quantity' => null]);
        $preview()->assertOk()->assertJsonPath('available_quantity', 0)->assertJsonPath('available', false);
    }

    public function test_submission_rechecks_new_conflicts_and_prevents_duplicate_requests(): void
    {
        $url = route('reservations.store', $this->resource->slug);
        $this->getJson(route('facilities.availability', ['slug' => $this->resource->slug, ...$this->schedule()]))->assertJsonPath('available', true);
        $this->booking(['quantity' => 35]);
        $this->postJson($url, $this->schedule())->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson($url, $this->schedule(['quantity' => 5]))->assertCreated();
        $this->postJson($url, $this->schedule(['quantity' => 5]))->assertUnprocessable()->assertJsonValidationErrors('reservation');
        $this->assertSame(1, Reservation::where('user_id', $this->resident->id)->count());
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_month_browsing_preserves_selected_date_events_outside_visible_month(): void
    {
        $this->booking(['start_time' => '13:15', 'end_time' => '14:00', 'quantity' => 10]);
        $this->get($this->page(['date' => '2026-10-22', 'month' => '2026-11']))->assertOk()
            ->assertSee('November 2026')->assertSee('1:15 PM')->assertSee('10 units allocated');
    }
}
