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

    public function test_calendar_defaults_show_current_month_combined_events_without_combined_availability_or_private_details(): void
    {
        $response = $this->get(route('calendar'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('All Schedules - eReserve')->assertSee('Browse facility and equipment schedules across barangays.')
            ->assertSee('Schedules for October 15, 2026')->assertDontSee('type="date"', false)
            ->assertDontSee('Select a resource first')->assertDontSee('View Facility')->assertDontSee('View Equipment')
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
            $this->get(route('calendar', ['barangay' => 'Washington', 'type' => $type, 'facility' => $this->court->slug, 'month' => '2026-10', 'date' => '2026-10-16']))->assertOk()
                ->assertViewHas('selectedType', $type)->assertViewHas('selectedFacility', fn ($resource) => $resource['id'] === $this->court->id)
                ->assertViewHas('selectedDate', fn ($date) => $date->toDateString() === '2026-10-16');
        }
        $response = $this->get(route('calendar', ['barangay' => 'Washington', 'type' => 'equipment', 'facility' => $this->court->slug, 'date' => '2026-10-15']))->assertOk()
            ->assertViewHas('selectedFacility', null)->assertViewHas('selectedType', 'equipment')
            ->assertViewHas('facilities', fn ($rows) => $rows->pluck('id')->all() === [$this->equipment->id])
            ->assertViewHas('calendarEvents', fn ($rows) => $rows->pluck('resource_id')->all() === [$this->equipment->id]);
        $this->fixture('equipment', $response->getContent());
        $response = $this->get(route('calendar', ['barangay' => 'Taft', 'type' => 'all', 'facility' => $this->court->slug, 'date' => '2026-10-15']))->assertOk()
            ->assertViewHas('selectedFacility', null)->assertViewHas('selectedBarangay', 'Taft')
            ->assertViewHas('calendarEvents', fn ($rows) => $rows->pluck('resource_id')->all() === [$this->other->id]);
        $this->fixture('taft', $response->getContent());
        $this->get(route('calendar', ['barangay' => 'Taft', 'type' => 'all', 'facility' => 'all', 'date' => '2026-11-03']))->assertOk()
            ->assertViewHas('month', fn ($month) => $month->format('Y-m') === '2026-11')
            ->assertViewHas('selectedDate', fn ($date) => $date->toDateString() === '2026-11-03');
        $this->assertSame('Washington', $this->resident->fresh()->barangay);
    }

    public function test_resource_selection_keeps_availability_but_is_read_only_even_with_bookmarked_times(): void
    {
        $query = ['barangay' => 'Washington', 'type' => 'all', 'facility' => $this->court->slug, 'date' => '2026-10-15', 'start_time' => '09:00', 'end_time' => '10:00'];
        $response = $this->get(route('calendar', $query))->assertOk()->assertDontSee('View Facility')->assertDontSee('Submit Reservation')
            ->assertSee('October 15, 2026: Partially Booked')->assertDontSee('schedule-slot-available')
            ->assertViewHas('selectedSlot', null)
            ->assertDontSee(route('facilities.show', ['slug' => $this->court->slug]));
        $this->fixture('resource', $response->getContent());
        $this->get(route('calendar', [...$query, 'facility' => 'all']))->assertOk()->assertViewHas('selectedSlot', null)
            ->assertDontSee('Submit Reservation')->assertDontSee('schedule-slot-available');
    }

    public function test_empty_and_nullable_filters_keep_a_useful_calendar_visible(): void
    {
        foreach ([['type' => '', 'facility' => ''], ['type' => null], ['facility' => 'missing']] as $filters) {
            $this->get(route('calendar', $filters))->assertOk()->assertViewHas('selectedType', 'all')->assertViewHas('selectedFacility', null)
                ->assertSee('class="calendar-grid"', false);
        }
        $response = $this->get(route('calendar', ['barangay' => 'Taft', 'type' => 'equipment']))->assertOk()
            ->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty())
            ->assertViewHas('calendarDays', fn ($days) => $days->count() === 31)
            ->assertSee('No equipment is currently available for this barangay.')
            ->assertSee('No events scheduled for this date.');
        $this->fixture('empty', $response->getContent());
    }

    public function test_accepted_only_and_overnight_events_include_previous_month_and_legacy_resource_matching(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->booking($this->court, ['status' => $status, 'reservation_date' => '2026-10-20']);
        }
        $legacy = $this->booking($this->court, ['facility_id' => null, 'reservation_date' => '2026-09-30', 'start_time' => '23:00', 'end_time' => '01:00']);
        $this->booking($this->equipment, ['reservation_date' => '2026-10-15', 'start_time' => '23:00', 'end_time' => '02:00']);
        $response = $this->get(route('calendar', ['month' => '2026-10', 'date' => '2026-10-01']))->assertOk()->assertSee('Continued from Sep 30, 2026');
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
            $this->assertCount(5, $events); // Keep the accepted reservation alongside the two overnight Official Uses.
            $this->assertCount(1, $events->where('event_type', 'reservation'));
            foreach ($events->where('event_type', 'official_use') as $event) {
                $this->assertSame($details, array_key_exists('purpose', $event));
            }
            $response->assertDontSee('Private official cancelled');
            if (! $details) {
                $response->assertDontSee('Private official active')->assertDontSee('Private official conflict');
            }
        }
    }

    public function test_month_navigation_keeps_selected_date_and_its_events_while_day_links_change_the_selection(): void
    {
        foreach (['all', $this->court->slug] as $slug) {
            $query = ['barangay' => 'Washington', 'type' => 'all', 'facility' => $slug, 'month' => '2026-11', 'date' => '2026-10-15'];
            $response = $this->get(route('calendar', $query))->assertOk()
                ->assertSee('Schedules for October 15, 2026')->assertSee('10:00 AM')->assertSee('11:00 AM')
                ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === ($slug === 'all' ? 2 : 1))
                ->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty())
                ->assertSee(route('calendar', array_replace($query, ['month' => '2026-12'])))
                ->assertSee(route('calendar', array_replace($query, ['date' => '2026-11-03'])));
            $this->get(route('calendar', array_replace($query, ['date' => '2026-11-03'])))->assertOk()
                ->assertSee('Schedules for November 3, 2026')
                ->assertSee('No events scheduled for this date.')
                ->assertViewHas('browseDateEvents', fn ($events) => $events->isEmpty());
            if ($slug === 'all') {
                $this->fixture('next-month', $response->getContent());
            }
        }
    }

    public function test_counts_and_panel_include_each_eligible_overnight_event_without_private_details(): void
    {
        $this->booking($this->court, ['reservation_date' => '2026-10-14', 'start_time' => '23:17', 'end_time' => '01:23']);
        $this->booking($this->equipment, ['status' => 'pending']);
        foreach (['active', 'conflict', 'cancelled'] as $status) {
            OfficialUse::create(['facility_id' => $this->equipment->id, 'barangay' => 'Washington', 'date' => '2026-10-14', 'start_time' => '23:30', 'end_time' => '02:15', 'purpose' => 'Private official purpose', 'status' => $status]);
        }
        $response = $this->get(route('calendar', ['date' => '2026-10-15']))->assertOk()
            ->assertSee('October 15, 2026: 5 events')->assertSee('October 14, 2026: 3 events')
            ->assertSee('Continued from Oct 14, 2026')->assertSee('datetime="2026-10-14T23:17:00+08:00"', false)
            ->assertSee('datetime="2026-10-15T01:23:00+08:00"', false)->assertSee('Official Use')
            ->assertDontSee('Private official purpose')->assertDontSee('Private Booker')->assertDontSee('Private booking purpose')
            ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === 5);
        $this->assertSame(5, substr_count($response->getContent(), 'data-event-type='));
        $this->fixture('overnight', $response->getContent());
        $this->get(route('calendar', ['facility' => $this->equipment->slug, 'date' => '2026-10-15']))->assertOk()
            ->assertDontSee('View Equipment')->assertDontSee('View Facility')->assertDontSee('datetime="2026-10-14T23:17:00+08:00"', false)
            ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === 3);
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
