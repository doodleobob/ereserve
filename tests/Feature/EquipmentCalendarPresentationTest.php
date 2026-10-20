<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EquipmentCalendarPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Facility $equipment;

    private User $resident;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 18)->startOfDay());
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->equipment = Facility::create([
            'barangay' => 'Taft', 'slug' => 'calendar-chairs', 'name' => 'Community folding chairs with padded seats',
            'category' => 'Equipment', 'description' => 'Chairs', 'capacity' => null, 'total_quantity' => 40,
            'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 10,
        ]);
    }

    private function booking(?int $quantity, string $start = '09:00', string $end = '10:00'): Reservation
    {
        return Reservation::create([
            'user_id' => $this->admin->id, 'facility_id' => $this->equipment->id, 'barangay' => 'Taft',
            'facility_slug' => $this->equipment->slug, 'facility_name' => $this->equipment->name,
            'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => '2026-10-20',
            'start_time' => $start, 'end_time' => $end, 'quantity' => $quantity, 'attendees' => null,
            'purpose' => 'Private resident purpose', 'status' => 'accepted', 'hourly_rate_snapshot' => 10,
        ]);
    }

    private function render(string $name, array $query = [])
    {
        $response = $this->actingAs($this->resident)->get(route(\App\Support\FacilityCatalog::detailRoute($this->equipment->category), [
            'slug' => $this->equipment->slug, 'date' => '2026-10-20', ...$query,
        ]))->assertOk();
        $labels = ['available' => 'Available', 'partial' => 'Partially Booked', 'full' => 'Fully Booked', 'unavailable' => 'Unavailable'];
        $status = $response->viewData('selectedDateStatus');
        $response->assertSee('<p class="selected-date-availability"><span class="legend-item legend-'.$status.'">'.$labels[$status].'</span></p>', false);
        if ($directory = getenv('EQUIPMENT_CALENDAR_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }

        return $response;
    }

    public static function allocations(): array
    {
        return [
            'empty' => ['empty', 0, 0, 0, 40, 'available'],
            'partial' => ['partial', 25, 0, 25, 15, 'partial'],
            'full' => ['full', 40, 0, 40, 0, 'full'],
            'combined' => ['combined', 20, 20, 40, 0, 'full'],
            'within stock' => ['within-stock', 10, 20, 30, 10, 'partial'],
            'over stock' => ['over-stock', 30, 20, 50, 0, 'full'],
        ];
    }

    #[DataProvider('allocations')]
    public function test_combined_slot_quantities_and_day_status_use_existing_availability(string $name, int $residentQuantity, int $officialQuantity, int $allocated, int $remaining, string $state): void
    {
        if ($residentQuantity) {
            $this->booking($residentQuantity);
        }
        if ($officialQuantity) {
            $this->actingAs($this->admin)->postJson(route('official-uses.store'), [
                'facility_id' => $this->equipment->id, 'date' => '2026-10-20', 'start_time' => '09:00',
                'end_time' => '10:00', 'quantity' => $officialQuantity, 'purpose' => 'Private official purpose',
            ])->assertCreated();
        }
        $response = $this->render($name);
        $response->assertSee('40 total units')->assertSee("{$allocated} of 40 units allocated")
            ->assertSee("{$remaining} units remaining")
            ->assertSee('equipment-slot equipment-slot-'.$state.'" data-equipment-slot="09:00"', false)
            ->assertDontSee('Private resident purpose')->assertDontSee('Private official purpose')->assertDontSee($this->admin->email);
        $slot = $response->viewData('schedule')->firstWhere('start_time', '09:00');
        $this->assertSame($allocated, $slot['allocated_quantity']);
        $this->assertSame($remaining, $slot['available_quantity']);
        $day = $response->viewData('calendarDays')->first(fn ($day) => $day['date']->day === 20);
        $this->assertSame($allocated ? 'partial' : 'available', $day['status']);
        $this->assertDatabaseCount('official_use_conflicts', $allocated > 40 ? 1 : 0);
        $this->assertSame($residentQuantity ? 1 : 0, substr_count($response->getContent(), 'data-event-type="reservation"'));
        $this->assertSame($officialQuantity ? 1 : 0, substr_count($response->getContent(), 'data-event-type="official_use"'));
    }

    public function test_partial_overlaps_display_peak_not_sum_and_keep_custom_overnight_times(): void
    {
        $this->booking(20, '09:00', '09:30');
        $this->booking(25, '09:30', '10:00');
        $this->booking(10, '09:20', '09:40');
        $this->booking(10, '23:17', '01:09');
        $response = $this->render('overlap', ['start_time' => '23:00', 'end_time' => '02:00', 'end_next_day' => 1]);
        $response->assertSee('35 of 40 units allocated')->assertSee('5 units remaining')
            ->assertSee('11:17 PM')->assertSee('1:09 AM')->assertSee('name="quantity"', false)
            ->assertSee('For an overnight booking, explicitly select Next day.');
        $this->assertSame('23:00', $response->viewData('selectedSlot')['start_time']);
        $this->assertSame('02:00', $response->viewData('selectedSlot')['end_time']);
        $this->assertSame(30, $response->viewData('selectedSlot')['available_quantity']);
        $this->render('continued', ['date' => '2026-10-21'])->assertSee('Continued from Oct 20, 2026');
    }

    public function test_unavailable_and_unverified_stock_never_show_available_indicators(): void
    {
        $this->equipment->update(['status' => 'Unavailable']);
        $this->render('unavailable')->assertDontSee('<meter', false)->assertDontSee('units remaining')
            ->assertSee('equipment-slot-unavailable');
        $this->equipment->update(['status' => 'Available', 'total_quantity' => null]);
        $this->render('unverified')->assertDontSee('<meter', false)->assertDontSee('units remaining')
            ->assertSee('Stock awaiting verification');
    }

    public function test_legacy_whole_resource_holds_remain_visible(): void
    {
        $this->booking(null);
        $this->render('legacy')->assertSee('Whole-resource hold (legacy booking)')
            ->assertSee('40 of 40 units allocated')->assertSee('0 units remaining');
    }

    public function test_facility_calendar_has_no_equipment_indicators_or_quantity_inputs(): void
    {
        $this->equipment->update(['category' => 'Facility', 'capacity' => 100, 'total_quantity' => null]);
        $this->render('facility', ['start_time' => '09:00', 'end_time' => '11:00'])
            ->assertDontSee('<meter', false)->assertDontSee('data-equipment-slot', false)
            ->assertSee('name="attendees"', false)->assertDontSee('name="quantity"', false);
    }

    public static function resourceCategories(): array
    {
        return [['Facility'], ['Equipment']];
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    #[DataProvider('resourceCategories')]
    public function test_date_links_update_status_slots_and_the_existing_booking_form(string $category): void
    {
        if ($category === 'Facility') {
            $this->equipment->update(['category' => 'Facility', 'capacity' => 100, 'total_quantity' => null]);
        }
        $this->actingAs($this->admin)->postJson(route('official-uses.store'), [
            'facility_id' => $this->equipment->id, 'date' => '2026-10-20',
            'start_time' => '08:00', 'end_time' => '17:00', 'quantity' => $category === 'Equipment' ? 40 : null,
            'purpose' => 'Official allocation',
        ])->assertCreated();
        $response = $this->render(strtolower($category).'-fully-booked');
        $this->assertSame('partial', $response->viewData('selectedDateStatus'));
        $document = $this->document($response->getContent());
        $this->assertSame('October 20, 2026: Partially Booked', $document->query('//a[@aria-current="date"]')->item(0)->getAttribute('aria-label'));
        $this->assertSame(15, $response->viewData('schedule')->where('status', 'available')->count());

        $nextDay = $document->query('//a[@aria-label="October 21, 2026: Available"]')->item(0);
        $response = $this->get($nextDay->getAttribute('href'))->assertOk();
        $this->assertSame('available', $response->viewData('selectedDateStatus'));
        $this->assertSame(24, $response->viewData('schedule')->where('status', 'available')->count());
        $document = $this->document($response->getContent());
        $this->assertSame(1, $document->query('//form[@data-unified-booking]')->length);
        $this->assertSame('2026-10-21', $document->query('//form[@data-unified-booking]//input[@name="reservation_date"]')->item(0)->getAttribute('value'));
        $slot = $document->query('//a[contains(@class,"equipment-slot-time") or contains(@class,"schedule-slot-available")]')->item(0);
        $response = $this->get($slot->getAttribute('href'))->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame('2026-10-21', $document->query('//input[@name="reservation_date"]')->item(0)->getAttribute('value'));
        $this->assertSame('00:00', $document->query('//select[@name="start_time"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('01:00', $document->query('//select[@name="end_time"]/option[@selected]')->item(0)->getAttribute('value'));
        if ($category === 'Equipment') {
            $this->assertSame(40, $response->viewData('selectedSlot')['available_quantity']);
        }

        $this->render(strtolower($category).'-past', ['date' => '2026-10-17'])
            ->assertDontSee('class="reservation-form custom-time-selection"', false);
        $this->assertSame('unavailable', $this->viewStatusForLinkedMonth());
    }

    private function viewStatusForLinkedMonth(): string
    {
        // A bookmarked date can be outside the displayed month; do not report another day's status.
        return $this->render('linked-month', ['month' => '2026-11', 'date' => '2026-10-17'])
            ->viewData('selectedDateStatus');
    }
}
