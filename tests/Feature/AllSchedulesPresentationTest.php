<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllSchedulesPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Facility $court;

    private Facility $equipment;

    private User $booker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 15)->setTime(8, 0));
        $this->booker = User::factory()->create(['name' => 'Private Schedule Booker', 'email' => 'private-schedule@example.test']);
        $this->court = $this->resource('Facility', 'Community Court');
        $this->equipment = $this->resource('Equipment', 'Sound Equipment');
    }

    private function resource(string $category, string $name): Facility
    {
        return Facility::create(['barangay' => 'Washington', 'category' => $category, 'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)), 'description' => 'Shared resource', 'capacity' => 50,
            'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => 100, 'reservation_access' => 'all_registered_users']);
    }

    private function booking(Facility $resource, array $overrides = []): Reservation
    {
        return Reservation::create(['user_id' => $this->booker->id, 'facility_id' => $resource->id,
            'facility_slug' => $resource->slug, 'facility_name' => $resource->name, 'barangay' => $resource->barangay,
            'category' => $resource->category, 'location' => $resource->location, 'status' => 'accepted',
            'reservation_date' => '2026-10-15', 'start_time' => '10:00', 'end_time' => '11:00',
            'purpose' => 'Private reservation notes', 'attendees' => 10, ...$overrides]);
    }

    public static function rolesAndEventCounts(): iterable
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            foreach ([0, 1, 5, 20, 50] as $count) {
                yield $role.'-'.$count => [$role, $count];
            }
        }
    }

    #[DataProvider('rolesAndEventCounts')]
    public function test_all_roles_share_action_free_complete_schedules(string $role, int $count): void
    {
        $viewer = User::factory()->create(['role' => $role, 'barangay' => 'Washington']);
        for ($i = 0; $i < $count; $i++) {
            if ($i % 5 === 4) {
                OfficialUse::create(['facility_id' => $this->equipment->id, 'barangay' => 'Washington',
                    'date' => '2026-10-15', 'start_time' => '11:00', 'end_time' => '12:00',
                    'purpose' => 'Authorized official details', 'status' => 'active']);
            } else {
                $this->booking($i % 2 ? $this->equipment : $this->court);
            }
        }
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->booking($this->court, compact('status'));
        }
        $query = ['barangay' => 'Washington', 'type' => 'all', 'facility' => 'all', 'month' => '2026-10', 'date' => '2026-10-15'];
        $response = $this->actingAs($viewer)->get(route('calendar', $query))->assertOk()
            ->assertViewIs('shared.calendar.index')
            ->assertViewHas('readOnlyCalendar', true)->assertViewHas('selectedSlot', null)
            ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === $count)
            ->assertSee('Schedules for October 15, 2026')->assertSee('Barangay Washington')
            ->assertDontSee('View Facility')->assertDontSee('View Equipment')->assertDontSee('Reserve Selected Time')
            ->assertDontSee('Private Schedule Booker')->assertDontSee('private-schedule@example.test')->assertDontSee('Private reservation notes')
            ->assertSee(route('calendar', array_replace($query, ['month' => '2026-11'])));
        $this->assertSame($count, substr_count($response->getContent(), 'data-event-type='));
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//link[@rel="stylesheet" and starts-with(@href,"'.asset('css/all-schedules.css').'?v=")]')->length);
        $this->assertSame(1, $xpath->query('//script[@defer and starts-with(@src,"'.asset('js/all-schedules.js').'?v=")]')->length);
        $this->assertSame(1, $xpath->query('//section[contains(@class,"all-schedules-layout")]/aside[contains(@class,"browse-schedules")]/h3[@id="schedule-heading"]')->length);
        $this->assertSame(1, $xpath->query('//section[contains(@class,"all-schedules-layout")]/aside[contains(@class,"browse-schedules")]/p[@id="schedule-summary"]')->length);
        $this->assertSame(0, $xpath->query('//aside[contains(@class,"browse-schedules")]//*[self::a or self::button or self::input or self::form]')->length);
        if ($count === 0) {
            $response->assertSee('No events scheduled for this date.')->assertDontSee('data-schedule-list')->assertDontSee('class="schedule-resource-group"', false);
        } else {
            $response->assertSee('October 15, 2026: '.$count.($count === 1 ? ' event' : ' events'))
                ->assertSee('data-schedule-list tabindex="0" role="region"', false)->assertSee('Reservation &bull; Booked', false)
                ->assertSee('datetime="2026-10-15T10:00:00+08:00"', false)->assertSee('datetime="2026-10-15T11:00:00+08:00"', false);
        }
        if ($role === 'user') {
            $response->assertDontSee('Authorized official details');
        } elseif ($count >= 5) {
            $response->assertSee('Authorized official details');
        }
        $this->fixture($role.'-'.$count, $response->getContent());
        if ($count === 50) {
            $changedDate = $this->get(route('calendar', array_replace($query, ['type' => 'equipment', 'date' => '2026-10-16'])))->assertOk()
                ->assertSee('No events scheduled for this date.');
            $this->fixture($role.'-date', $changedDate->getContent());
            $changedFilter = $this->get(route('calendar', array_replace($query, ['type' => 'equipment'])))->assertOk()
                ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === 30);
            $this->fixture($role.'-filter', $changedFilter->getContent());
        }

        $selected = $this->get(route('calendar', array_replace($query, ['facility' => $this->court->slug, 'start_time' => '10:00', 'end_time' => '11:00'])))->assertOk()
            ->assertViewHas('selectedSlot', null)->assertDontSee('View Facility')->assertDontSee('Submit Reservation')->assertDontSee('schedule-slot-available');
        if ($count === 0) {
            $selected->assertDontSee('class="schedule-resource-group"', false)->assertSee('No events scheduled for this date.');
        }
        $this->get(route('calendar', array_replace($query, ['month' => '2026-11'])))->assertOk()
            ->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty())
            ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === $count);
    }

    public function test_three_identical_events_share_the_same_scroll_markup_for_every_role(): void
    {
        $hall = $this->resource('Facility', 'Meeting Hall');
        $this->booking($this->court);
        foreach ([$this->equipment, $hall] as $resource) {
            OfficialUse::create(['facility_id' => $resource->id, 'barangay' => 'Washington',
                'date' => '2026-10-15', 'start_time' => '11:00', 'end_time' => '12:00',
                'purpose' => 'Authorized official details', 'status' => 'active']);
        }
        $query = ['barangay' => 'Washington', 'type' => 'all', 'facility' => 'all', 'month' => '2026-10', 'date' => '2026-10-15'];
        $expectedEvents = null;
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $viewer = User::factory()->create(['role' => $role, 'barangay' => 'Washington']);
            $response = $this->actingAs($viewer)->get(route('calendar', $query))->assertOk()
                ->assertViewIs('shared.calendar.index')->assertViewHas('readOnlyCalendar', true)
                ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === 3)
                ->assertSee('October 15, 2026: 3 events')
                ->assertSee('data-schedule-list tabindex="0" role="region"', false)
                ->assertSee('css/all-schedules.css')->assertSee('js/all-schedules.js')
                ->assertDontSee('View Facility')->assertDontSee('Reserve Selected Time');
            // Compare the same records, excluding only legitimately privileged details.
            $events = $response->viewData('browseDateEvents')->map(fn ($event) => collect($event)->except(['purpose', 'status'])->all())->all();
            $expectedEvents ??= $events;
            $this->assertSame($expectedEvents, $events);
            if ($role === 'user') {
                $response->assertDontSee('Authorized official details');
            } else {
                $response->assertSee('Authorized official details');
            }
            $this->fixture($role.'-3', $response->getContent());
        }
    }

    public function test_overnight_cards_show_original_dates_and_counts_respect_exclusive_midnight_end(): void
    {
        $reservation = $this->booking($this->court, ['reservation_date' => '2026-10-14', 'start_time' => '23:00', 'end_time' => '12:00']);
        $this->booking($this->court, ['reservation_date' => '2026-10-14', 'start_time' => '22:00', 'end_time' => '00:00']);
        $official = OfficialUse::create(['facility_id' => $this->equipment->id, 'barangay' => 'Washington', 'date' => '2026-10-14',
            'start_time' => '23:30', 'end_time' => '02:15', 'purpose' => 'Authorized overnight purpose', 'status' => 'active']);
        $originalReservation = $reservation->fresh()->getRawOriginal();
        $originalOfficial = $official->fresh()->getRawOriginal();
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => 'Washington']));
            foreach (['2026-10-14' => 3, '2026-10-15' => 2, '2026-10-16' => 0] as $date => $count) {
                $response = $this->get(route('calendar', ['date' => $date]))->assertOk()
                    ->assertSee('October 14, 2026: 3 events')->assertSee('October 15, 2026: 2 events')->assertSee('October 16, 2026: No events')
                    ->assertViewHas('browseDateEvents', fn ($events) => $events->count() === $count);
                $this->assertSame($count, substr_count($response->getContent(), 'data-event-type='));
                if ($date === '2026-10-15') {
                    $response->assertSee('Continued from Oct 14, 2026')
                        ->assertSee('datetime="2026-10-14T23:00:00+08:00"', false)->assertSee('datetime="2026-10-15T12:00:00+08:00"', false)
                        ->assertSee('datetime="2026-10-14T23:30:00+08:00"', false)->assertSee('datetime="2026-10-15T02:15:00+08:00"', false)
                        ->assertDontSee('datetime="2026-10-15T00:00:00+08:00"', false);
                }
            }
        }
        $this->assertSame($originalReservation, $reservation->fresh()->getRawOriginal());
        $this->assertSame($originalOfficial, $official->fresh()->getRawOriginal());
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
