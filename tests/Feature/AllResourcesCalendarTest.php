<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllResourcesCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private Facility $court;

    private Facility $equipment;

    private Facility $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $this->court = $this->resource('Washington', 'Facility', 'Community Court');
        $this->equipment = $this->resource('Washington', 'Equipment', 'Sound Equipment');
        $this->other = $this->resource('Taft', 'Facility', 'Taft Hall');
        foreach ([$this->court, $this->equipment, $this->other] as $resource) {
            $this->booking($resource);
        }
        $this->actingAs($this->resident);
    }

    private function resource(string $barangay, string $category, string $name): Facility
    {
        return Facility::create(['barangay' => $barangay, 'category' => $category, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'description' => 'Calendar resource', 'capacity' => 50, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => 100, 'reservation_access' => 'all_registered_users']);
    }

    private function booking(Facility $resource, array $overrides = []): Reservation
    {
        return Reservation::create([
            'user_id' => User::factory()->create(['name' => 'Private Booker', 'email' => uniqid().'@private.test'])->id,
            'facility_id' => $resource->id, 'facility_slug' => $resource->slug, 'facility_name' => $resource->name, 'barangay' => $resource->barangay,
            'category' => $resource->category, 'location' => $resource->location, 'status' => 'accepted', 'reservation_date' => '2026-10-15',
            'start_time' => '10:00', 'end_time' => '11:00', 'purpose' => 'Private booking purpose', 'attendees' => 10,
            ...$overrides,
        ]);
    }

    public function test_dashboard_defaults_show_current_month_combined_events_without_combined_availability_or_private_details(): void
    {
        $response = $this->get(route('dashboard'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('selectedBarangay', 'Washington')->assertViewHas('selectedType', 'all')->assertViewHas('selectedFacility', null)
            ->assertViewHas('month', fn ($month) => $month->format('Y-m') === '2026-10')
            ->assertViewHas('selectedDate', fn ($date) => $date->toDateString() === '2026-10-15')
            ->assertSee('value="all" selected>All Types', false)->assertSee('value="all" selected>All Facilities &amp; Equipment', false)
            ->assertSee('October 15, 2026: 2 events')->assertSee('Community Court')->assertSee('Sound Equipment')
            ->assertDontSee('Taft Hall')->assertDontSee('Private Booker')->assertDontSee('Private booking purpose')->assertDontSee('@private.test')
            ->assertDontSee('calendar-day-full')->assertDontSee('calendar-day-partial')->assertDontSee('calendar-day-available')
            ->assertDontSee('Submit Reservation')->assertDontSee('schedule-slot-available')->assertViewHas('selectedSlot', null);
        $this->assertCount(31, $response->viewData('calendarDays'));
        $this->assertCount(0, $response->viewData('schedule'));
        $this->assertEqualsCanonicalizing([$this->court->id, $this->equipment->id], $response->viewData('calendarEvents')->pluck('resource_id')->all());
        foreach ($response->viewData('calendarDays') as $day) {
            $this->assertArrayNotHasKey('status', $day);
            $this->assertArrayNotHasKey('available_slots', $day);
        }
        $this->fixture('all', $response->getContent());
    }

    public function test_url_filters_preserve_valid_resource_date_and_month_and_reset_only_invalid_resource(): void
    {
        foreach (['all', 'facility'] as $type) {
            $this->get(route('dashboard', ['barangay' => 'Washington', 'type' => $type, 'facility' => $this->court->slug, 'month' => '2026-10', 'date' => '2026-10-16']))->assertOk()
                ->assertViewHas('selectedType', $type)->assertViewHas('selectedFacility', fn ($resource) => $resource['id'] === $this->court->id)
                ->assertViewHas('selectedDate', fn ($date) => $date->toDateString() === '2026-10-16');
        }
        $response = $this->get(route('dashboard', ['barangay' => 'Washington', 'type' => 'equipment', 'facility' => $this->court->slug, 'date' => '2026-10-15']))->assertOk()
            ->assertViewHas('selectedFacility', null)->assertViewHas('selectedType', 'equipment')
            ->assertViewHas('facilities', fn ($rows) => $rows->pluck('id')->all() === [$this->equipment->id])
            ->assertViewHas('calendarEvents', fn ($rows) => $rows->pluck('resource_id')->all() === [$this->equipment->id]);
        $this->fixture('equipment', $response->getContent());
        $response = $this->get(route('dashboard', ['barangay' => 'Taft', 'type' => 'all', 'facility' => $this->court->slug, 'date' => '2026-10-15']))->assertOk()
            ->assertViewHas('selectedFacility', null)->assertViewHas('selectedBarangay', 'Taft')
            ->assertViewHas('calendarEvents', fn ($rows) => $rows->pluck('resource_id')->all() === [$this->other->id]);
        $this->fixture('taft', $response->getContent());
        $this->get(route('dashboard', ['barangay' => 'Taft', 'type' => 'all', 'facility' => 'all', 'date' => '2026-11-03']))->assertOk()
            ->assertViewHas('month', fn ($month) => $month->format('Y-m') === '2026-11')
            ->assertViewHas('selectedDate', fn ($date) => $date->toDateString() === '2026-11-03');
        $this->assertSame('Washington', $this->resident->fresh()->barangay);
    }

    public function test_resource_selection_restores_existing_availability_and_booking_actions(): void
    {
        $query = ['barangay' => 'Washington', 'type' => 'all', 'facility' => $this->court->slug, 'date' => '2026-10-15', 'start_time' => '08:00', 'end_time' => '09:00'];
        $response = $this->get(route('dashboard', $query))->assertOk()->assertSee('Submit Reservation')
            ->assertSee('October 15, 2026: Partially Booked')->assertSee('schedule-slot-available')
            ->assertViewHas('selectedSlot', fn ($slot) => $slot['status'] === 'available');
        $this->fixture('resource', $response->getContent());
        $this->get(route('dashboard', [...$query, 'facility' => 'all']))->assertOk()->assertViewHas('selectedSlot', null)
            ->assertDontSee('Submit Reservation')->assertDontSee('schedule-slot-available');
    }

    public function test_empty_and_nullable_filters_keep_a_useful_calendar_visible(): void
    {
        foreach ([['type' => '', 'facility' => ''], ['type' => null], ['facility' => 'missing']] as $filters) {
            $this->get(route('dashboard', $filters))->assertOk()->assertViewHas('selectedType', 'all')->assertViewHas('selectedFacility', null)
                ->assertSee('class="calendar-grid"', false);
        }
        $response = $this->get(route('dashboard', ['barangay' => 'Taft', 'type' => 'equipment']))->assertOk()
            ->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty())
            ->assertViewHas('calendarDays', fn ($days) => $days->count() === 31)
            ->assertSee('No equipment is currently available for this barangay.')
            ->assertSee('No bookings or Official Use events for this date.');
        $this->fixture('empty', $response->getContent());
    }

    public function test_accepted_only_and_overnight_events_include_previous_month_and_legacy_resource_matching(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->booking($this->court, ['status' => $status, 'reservation_date' => '2026-10-20']);
        }
        $legacy = $this->booking($this->court, ['facility_id' => null, 'reservation_date' => '2026-09-30', 'start_time' => '23:00', 'end_time' => '01:00']);
        $this->booking($this->equipment, ['reservation_date' => '2026-10-15', 'start_time' => '23:00', 'end_time' => '02:00']);
        $response = $this->get(route('dashboard', ['month' => '2026-10', 'date' => '2026-10-01']))->assertOk()->assertSee('Continued from Sep 30, 2026');
        $events = $response->viewData('calendarEvents');
        $this->assertCount(0, $events->filter(fn ($event) => str_starts_with($event['start'], '2026-10-20')));
        $this->assertCount(1, $events->filter(fn ($event) => $event['start'] === '2026-10-01T00:00:00' && $event['resource_id'] === $this->court->id));
        $this->assertCount(1, $events->filter(fn ($event) => $event['start'] === '2026-10-16T00:00:00' && $event['end'] === '2026-10-16T02:00:00'));
        $this->assertNull($legacy->fresh()->facility_id);
    }

    public function test_official_use_visibility_and_unavailable_resource_rules_match_specific_calendars_for_every_role(): void
    {
        foreach (['active', 'conflict', 'cancelled'] as $status) {
            OfficialUse::create(['facility_id' => $this->other->id, 'barangay' => 'Taft', 'date' => '2026-10-15', 'start_time' => '23:00', 'end_time' => '01:00', 'purpose' => 'Private official '.$status, 'status' => $status]);
        }
        $this->other->update(['status' => 'Unavailable']);
        foreach ([['user', 'Taft', false], ['user', 'Washington', false], ['admin', 'Washington', false], ['admin', 'Taft', true], ['super_admin', 'Washington', true]] as [$role, $barangay, $details]) {
            $viewer = User::factory()->create(compact('role', 'barangay'));
            $response = $this->actingAs($viewer)->get(route('calendar', ['barangay' => 'Taft', 'date' => '2026-10-15']))->assertOk();
            $events = $response->viewData('calendarEvents');
            $this->assertCount(4, $events); // Two active/conflict events, each split across midnight.
            foreach ($events as $event) {
                $this->assertSame('official_use', $event['event_type']);
                $this->assertSame($details, array_key_exists('purpose', $event));
            }
            $response->assertDontSee('Private official cancelled');
            if (! $details) {
                $response->assertDontSee('Private official active')->assertDontSee('Private official conflict');
            }
        }
    }

    private function fixture(string $name, string $html): void
    {
        if ($directory = getenv('CALENDAR_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
