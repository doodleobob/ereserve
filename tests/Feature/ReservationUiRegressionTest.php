<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\OfficialUseConflict;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReservationUiRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 20)->startOfDay());
    }

    private function resource(string $category): Facility
    {
        return Facility::create([
            'barangay' => 'Taft', 'slug' => strtolower($category), 'name' => 'Community Resource',
            'category' => $category, 'description' => 'Resource', 'capacity' => 50,
            'total_quantity' => $category === 'Equipment' ? 100 : null,
            'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100,
        ]);
    }

    private function booking(Facility $resource, User $resident): Reservation
    {
        return Reservation::create([
            'user_id' => $resident->id, 'facility_id' => $resource->id, 'barangay' => 'Taft',
            'facility_slug' => $resource->slug, 'facility_name' => $resource->name,
            'category' => $resource->category, 'location' => 'Taft',
            'reservation_date' => '2026-10-23', 'start_time' => '08:00', 'end_time' => '10:00',
            'purpose' => 'Community meeting', 'attendees' => $resource->isEquipment() ? null : 10,
            'quantity' => $resource->isEquipment() ? 20 : null, 'status' => 'accepted',
            'hourly_rate_snapshot' => 100, 'total_payment' => 200,
        ]);
    }

    public function test_resident_can_submit_multiple_reservations_but_real_schedule_conflicts_remain_blocked(): void
    {
        $resident = User::factory()->create(['barangay' => 'Taft']);
        $this->actingAs($resident);
        foreach (['Facility', 'Equipment'] as $category) {
            $resource = $this->resource($category);
            $reservation = $this->booking($resource, $resident);
            if ($category === 'Equipment') {
                $reservation->update(['quantity' => 100]);
            }
            $data = ['reservation_date' => '2026-10-24', 'start_time' => '08:00', 'end_time' => '10:00',
                'purpose' => 'Another meeting', 'attendees' => 10, 'quantity' => 20];
            foreach (['pending', 'accepted'] as $index => $status) {
                $reservation->update(['status' => $status]);
                $requestData = [...$data, 'start_time' => $index === 0 ? '08:00' : '10:00', 'end_time' => $index === 0 ? '10:00' : '12:00'];
                $this->postJson(route('reservations.store', $resource->slug), $requestData)
                    ->assertCreated();
                $this->assertSame($status === 'pending' ? 2 : 3, $resource->reservations()->count());
                $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => $status]);

            }

            if ($category === 'Facility') {
                $resource->reservations()->where('start_time', '08:00')->update(['status' => 'accepted']);
            }
            $this->postJson(route('reservations.store', $resource->slug), [...$data, 'start_time' => '10:00', 'end_time' => '12:00'])
                ->assertUnprocessable()->assertJsonPath('errors.reservation.0', "You already have a reservation for this ".strtolower($category)." during this time.");

            $resource->reservations()->where('status', 'pending')->update(['status' => 'cancelled']);
            $adjacent = [...$data, 'start_time' => '10:00', 'end_time' => '11:00'];
            $this->postJson(route('reservations.store', $resource->slug), $adjacent)->assertCreated();
            if ($category === 'Facility') {
                $this->postJson(route('reservations.store', $resource->slug), [...$data, 'start_time' => '09:00', 'end_time' => '11:00'])
                    ->assertUnprocessable()->assertJsonValidationErrors('reservation');
            } else {
                $this->postJson(route('reservations.store', $resource->slug), [...$data, 'start_time' => '09:00', 'end_time' => '11:00', 'quantity' => 101])
                    ->assertUnprocessable()->assertJsonPath('errors.reservation.0', 'You already have a reservation for this equipment during this time.');
            }
            $resource->reservations()->update(['status' => 'cancelled']);

            $otherResource = Facility::create([
                'barangay' => 'Taft', 'slug' => strtolower($category).'-other', 'name' => 'Another Resource',
                'category' => $category, 'description' => 'Resource', 'capacity' => 50,
                'total_quantity' => $category === 'Equipment' ? 100 : null,
                'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100,
            ]);
            $differentFacilityData = ['reservation_date' => '2026-10-24', 'start_time' => '08:00', 'end_time' => '10:00',
                'purpose' => 'Another resource', 'attendees' => 10, 'quantity' => 20];
            $this->postJson(route('reservations.store', $otherResource->slug), $differentFacilityData)->assertCreated();

            $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
            OfficialUse::create(['facility_id' => $resource->id, 'barangay' => 'Taft', 'date' => '2026-10-24',
                'start_time' => '12:00', 'end_time' => '13:00', 'quantity' => $category === 'Equipment' ? 100 : null,
                'purpose' => 'Official event', 'created_by' => $admin->id, 'status' => 'active']);
            $this->postJson(route('reservations.store', $resource->slug), [...$data, 'start_time' => '12:00', 'end_time' => '13:00'])
                ->assertUnprocessable()->assertJsonValidationErrors($category === 'Equipment' ? 'quantity' : 'reservation');

            $this->assertSame(4, $resource->reservations()->where('user_id', $resident->id)->count());
            $this->assertSame(1, $otherResource->reservations()->where('user_id', $resident->id)->count());
            $this->assertDatabaseCount('payments', 0);
        }
    }

    public function test_conflict_details_and_preferences_remain_accessible_with_compact_table_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $resident = User::factory()->create(['barangay' => 'Taft']);
        $resource = $this->resource('Equipment');
        $reservation = $this->booking($resource, $resident);
        $use = OfficialUse::create([
            'facility_id' => $resource->id, 'barangay' => 'Taft', 'date' => '2026-10-23',
            'start_time' => '00:00', 'end_time' => '19:00', 'purpose' => 'Barangay assembly',
            'created_by' => $admin->id, 'status' => 'conflict',
        ]);
        $conflict = OfficialUseConflict::create(['official_use_id' => $use->id, 'reservation_id' => $reservation->id, 'resolution' => 'awaiting_user_decision']);
        $before = $reservation->fresh()->getAttributes();

        foreach (['awaiting_user_decision', 'reschedule_requested', 'cancellation_requested'] as $resolution) {
            $conflict->update(['resolution' => $resolution]);
            $html = $this->actingAs($admin)->get(route('reservations.index'))->assertOk()->getContent();
            $dom = new \DOMDocument;
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);
            $status = $xpath->query('//tr[@data-reservation-id="'.$reservation->id.'"]/td[7]')->item(0);
            foreach (['Accepted', 'Official Use Conflict', $conflict->resolutionLabel()] as $label) {
                $this->assertStringContainsString($label, $status->textContent);
            }
            $this->assertStringNotContainsString('2026', $status->textContent);
            $this->assertStringNotContainsString('Affected Schedule', $status->textContent);
            $this->assertSame('view-'.$reservation->id, $status->getElementsByTagName('button')->item(0)->getAttribute('data-reservation-open'));
            $details = $dom->getElementById('view-'.$reservation->id);
            $this->assertStringContainsString('Official Use Conflict #'.$use->id, $details->textContent);
            $this->assertStringContainsString('October 23, 2026', $details->textContent);
            $this->assertStringContainsString('12:00 AM', $details->textContent);
            $this->assertStringContainsString('7:00 PM', $details->textContent);
            if ($resolution === 'awaiting_user_decision') {
                $this->capture('admin', $html);
            }
        }

        $conflict->update(['resolution' => 'awaiting_user_decision']);
        foreach ([false, true] as $overnight) {
            if ($overnight) {
                $use->update(['start_time' => '23:00', 'end_time' => '02:00']);
            }
            $response = $this->actingAs($resident)->get(route('reservations.index'))->assertOk()
                ->assertSee('Official Use Conflict #'.$use->id)->assertSee('Affected Schedule')
                ->assertSee('Request Reschedule')->assertSee('Request Cancellation')
                ->assertSee('The barangay administrator will process the change.')
                ->assertSee('data-modal-open="conflict-'.$conflict->id.'-reschedule"', false)
                ->assertSee('data-modal-open="conflict-'.$conflict->id.'-cancel"', false);
            if ($overnight) {
                $response->assertSee('October 24, 2026')->assertSee('11:00 PM')->assertSee('2:00 AM');
            }
            $this->capture($overnight ? 'resident-overnight' : 'resident', $response->getContent());
        }
        $this->assertSame($before, $reservation->fresh()->getAttributes());
    }

    private function capture(string $name, string $html): void
    {
        if ($directory = getenv('RESERVATION_UI_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
