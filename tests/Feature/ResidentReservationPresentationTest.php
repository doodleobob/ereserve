<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResidentReservationPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private Facility $equipment;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $base = ['barangay' => 'Washington', 'description' => 'Community resource', 'capacity' => 50,
            'location' => 'Barangay center', 'status' => 'Available', 'hourly_rate' => '50.00'];
        // Deliberately misleading names: classification must come from stored categories.
        $this->equipment = Facility::create([...$base, 'name' => 'Court Equipment', 'slug' => 'chairs', 'category' => 'Equipment', 'total_quantity' => 100]);
        $this->facility = Facility::create([...$base, 'name' => 'Chairs Hall', 'slug' => 'hall', 'category' => 'Facility']);
        $this->actingAs($this->resident);
    }

    private function booking(Facility $resource, array $overrides = []): Reservation
    {
        return Reservation::create([
            'user_id' => $this->resident->id, 'facility_id' => $resource->id, 'facility_slug' => $resource->slug,
            'facility_name' => $resource->name, 'barangay' => $resource->barangay, 'category' => $resource->category,
            'location' => $resource->location, 'reservation_date' => '2026-10-22', 'start_time' => '13:00',
            'end_time' => '17:00', 'purpose' => 'XXX', 'quantity' => $resource->isEquipment() ? 20 : null,
            'attendees' => $resource->isEquipment() ? null : 30, 'status' => 'accepted',
            'hourly_rate_snapshot' => '50.00', 'total_payment' => '50.00', ...$overrides,
        ]);
    }

    private function card(Reservation $reservation): string
    {
        $response = $this->get(route('reservations.index', ['reservation' => $reservation->id]))->assertOk();
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return $dom->saveHTML($dom->getElementById('resident-reservation-'.$reservation->id));
    }

    public function test_card_types_quantities_attendees_and_links_use_stored_classification(): void
    {
        $equipment = $this->booking($this->equipment);
        $facility = $this->booking($this->facility);
        foreach ([[$equipment, 'Equipment', 'equipment.show'], [$facility, 'Facility', 'facilities.show']] as [$reservation, $type, $route]) {
            $card = $this->card($reservation);
            $this->assertStringContainsString('resident-resource-type">'.$type.'</span>', $card);
            $this->assertStringContainsString('Barangay Washington', $card);
            $this->assertStringContainsString('Reservation #'.$reservation->id, $card);
            $this->assertStringContainsString('reservation-status-accepted">Booked', $card);
            $this->assertStringContainsString(route($route, $reservation->facility_slug), $card);
            $this->assertStringContainsString('View '.$type, $card);
            $this->assertStringContainsString('<dt>Purpose</dt><dd>XXX</dd>', $card);
        }
        $this->assertStringContainsString('<dt>Quantity Reserved</dt><dd>20 units</dd>', $this->card($equipment));
        $this->assertStringNotContainsString('<dt>Attendees', $this->card($equipment));
        $this->assertStringContainsString('<dt>Attendees</dt><dd>30</dd>', $this->card($facility));
        $this->assertStringNotContainsString('Quantity Reserved', $this->card($facility));
    }

    public function test_legacy_quantity_and_missing_resource_preserve_truthful_history(): void
    {
        $legacy = $this->booking($this->equipment, ['quantity' => null, 'facility_id' => null, 'facility_slug' => 'deleted-equipment']);
        $before = $legacy->fresh()->getAttributes();
        $card = $this->card($legacy);
        $this->assertStringContainsString('Equipment</span>', $card);
        $this->assertStringContainsString('Not recorded (legacy booking)', $card);
        $this->assertStringNotContainsString('100 units', $card);
        $this->assertStringNotContainsString('View Equipment', $card);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $single = $this->booking($this->equipment, ['quantity' => 1]);
        $this->assertStringContainsString('<dd>1 unit</dd>', $this->card($single));
    }

    public function test_purposes_are_preserved_escaped_and_never_treated_as_placeholders(): void
    {
        foreach (['XXX', '0', "Family & friends <script>alert('test')</script>\n".str_repeat('LongPurpose', 30), ''] as $purpose) {
            $reservation = $this->booking($this->facility, ['purpose' => $purpose]);
            $this->get(route('reservations.index', ['reservation' => $reservation->id]))->assertOk()
                ->assertSee('<dt>Purpose</dt><dd>'.e($purpose === '' ? 'Not recorded' : $purpose).'</dd>', false)
                ->assertDontSee("<script>alert('test')</script>", false);
            $this->assertSame($purpose, $reservation->fresh()->purpose);
        }
    }

    public function test_same_day_overnight_and_historical_minutes_keep_exact_times(): void
    {
        foreach ([
            ['13:00', '17:00', '4 hours', '1:00 PM - 5:00 PM', false],
            ['23:00', '03:00', '4 hours', '11:00 PM', true],
            ['23:35', '01:10', '1 hour 35 minutes', '11:35 PM', true],
            ['13:05', '13:50', '45 minutes', '1:05 PM - 1:50 PM', false],
        ] as [$start, $end, $duration, $time, $overnight]) {
            $reservation = $this->booking($this->facility, ['start_time' => $start, 'end_time' => $end]);
            $before = $reservation->fresh()->getAttributes();
            $card = $this->card($reservation);
            $this->assertStringContainsString('October 22, 2026', $card);
            $this->assertStringContainsString($time, $card);
            $this->assertStringContainsString('<dt>Duration</dt><dd>'.$duration.'</dd>', $card);
            if ($overnight) {
                $this->assertStringContainsString('October 23, 2026', $card);
                $this->assertStringContainsString($reservation->period()->end->format('g:i A'), $card);
                $this->assertStringContainsString('<dt>End</dt>', $card);
            } else {
                $this->assertStringNotContainsString('October 23, 2026', $card);
            }
            $this->assertSame($before, $reservation->fresh()->getAttributes());
        }
    }

    public function test_payment_labels_follow_status_and_stored_totals_without_repricing(): void
    {
        foreach (['paid', 'pending', 'refund_pending', 'refunded', 'cancelled'] as $status) {
            $reservation = $this->booking($this->equipment);
            $payment = $reservation->payment()->create(['amount' => '999.00', 'payment_status' => $status]);
            $before = $payment->fresh()->getAttributes();
            $card = $this->card($reservation);
            $this->assertStringContainsString('<dt>'.($status === 'paid' ? 'Total Paid' : 'Total Payment').'</dt><dd>₱50.00</dd>', $card);
            $this->assertStringContainsString($status === 'pending' ? 'Payment Pending' : \App\Models\Payment::statusLabel($status), $card);
            $this->assertStringNotContainsString('999.00', $card);
            if ($status !== 'paid') {
                $this->assertStringNotContainsString('Total Paid', $card);
            }
            $this->assertSame($before, $payment->fresh()->getAttributes());
            $this->assertSame('50.00', $reservation->fresh()->total_payment);
        }
        foreach (['pending', 'cancelled'] as $reservationStatus) {
            $reservation = $this->booking($this->facility, ['status' => $reservationStatus]);
            $reservation->payment()->create(['amount' => '50.00', 'payment_status' => 'refund_pending']);
            $this->assertStringContainsString('Refund Pending', $this->card($reservation));
        }
    }

    public function test_mixed_resource_sorting_search_filters_and_privacy_are_unchanged(): void
    {
        $equipment = $this->booking($this->equipment, ['purpose' => 'Community equipment']);
        $facility = $this->booking($this->facility, ['purpose' => 'Community hall', 'status' => 'pending']);
        $private = $this->booking($this->facility, ['user_id' => User::factory()->create()->id, 'purpose' => 'Private resident details']);
        $this->get(route('reservations.index', ['sort' => 'facility']))->assertOk()
            ->assertSee('>Resource Name</option>', false)
            ->assertViewHas('reservations', fn ($rows) => $rows->modelKeys() === [$facility->id, $equipment->id])
            ->assertDontSee('Private resident details');
        $this->get(route('reservations.index', ['search' => 'Community', 'status' => 'accepted', 'sort' => 'facility']))->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->modelKeys() === [$equipment->id]);
        $this->get(route('reservations.index', ['reservation' => $private->id]))->assertOk()->assertSee('No reservations found')->assertDontSee('Private resident details');
    }

    public function test_rendered_page_is_read_only_and_exports_responsive_fixtures(): void
    {
        $equipment = $this->booking($this->equipment);
        $equipment->payment()->create(['amount' => '50.00', 'payment_status' => 'paid']);
        $this->booking($this->facility, ['status' => 'pending', 'purpose' => "Community celebration & neighborhood gathering\n".str_repeat('LongUnbrokenPurpose', 20)]);
        $this->booking($this->equipment, ['quantity' => null, 'start_time' => '23:35', 'end_time' => '01:10', 'purpose' => 'Legacy overnight booking']);
        $tables = ['reservations', 'payments', 'facilities', 'notifications'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $response = $this->get(route('reservations.index'))->assertOk();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        Notification::assertNothingSent();
        if ($directory = getenv('RESIDENT_PRESENTATION_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/reservations.html', $response->getContent());
        }
    }
}
