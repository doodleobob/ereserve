<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ResidentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 04:00:00', 'UTC'));
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $this->actingAs($this->resident);
    }

    private function booking(array $attributes = []): Reservation
    {
        return Reservation::create(array_merge([
            'user_id' => $this->resident->id, 'barangay' => 'Washington',
            'facility_slug' => 'community-court', 'facility_name' => 'Community Court',
            'category' => 'Facility', 'location' => 'Washington',
            'reservation_date' => '2026-10-15', 'start_time' => '13:00', 'end_time' => '14:00',
            'status' => 'accepted', 'purpose' => 'Private purpose', 'attendees' => 5,
        ], $attributes));
    }

    private function renderFixture(string $name, string $html): void
    {
        if ($directory = getenv('RESIDENT_DASHBOARD_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    public function test_only_the_nearest_future_accepted_booking_is_shown_with_existing_navigation(): void
    {
        $this->booking(['reservation_date' => '2026-10-16', 'facility_name' => 'Later day']);
        $this->booking(['start_time' => '15:00', 'end_time' => '16:00', 'facility_name' => 'Later today']);
        $nearest = $this->booking(['barangay' => 'Taft', 'category' => 'Equipment',
            'facility_name' => 'Portable Sound System for Community Events and Barangay Gatherings']);
        $before = Reservation::orderBy('id')->get()->toArray();
        $response = $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('upcomingReservation', fn ($row) => $row->is($nearest))
            ->assertSee('Your Upcoming Schedule')->assertSee($nearest->facility_name)->assertSee('Taft')
            ->assertSee('Oct 15, 2026')->assertSee('1:00 PM')->assertSee('2:00 PM')
            ->assertSee('reservation-status-accepted')->assertDontSee('Later day')->assertDontSee('Later today')
            ->assertDontSee('Private purpose')->assertSee('Browse Facilities')->assertSee('View All Schedules')
            ->assertSee('My Reservations')->assertSee('notification-bell');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $section = '//section[contains(@class, "resident-upcoming")]';
        $this->assertSame(1, $xpath->query($section.'//article')->length);
        $this->assertSame(0, $xpath->query($section.'//a | '.$section.'//button | '.$section.'//input | '.$section.'//summary')->length);
        foreach (['facilities', 'calendar', 'reservations.index'] as $route) {
            $this->assertGreaterThan(0, $xpath->query('//a[@href="'.route($route).'"]')->length);
            $this->get(route($route))->assertOk();
        }
        $this->assertSame($before, Reservation::orderBy('id')->get()->toArray());
        $this->renderFixture('scheduled', $response->getContent());
    }

    public function test_ongoing_booking_precedes_future_booking_in_manila_time(): void
    {
        $this->booking(['facility_name' => 'Future booking']);
        $ongoing = $this->booking(['start_time' => '11:00', 'end_time' => '12:30']);
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('upcomingReservation', fn ($row) => $row->is($ongoing))
            ->assertDontSee('Future booking');
    }

    public function test_overnight_booking_survives_midnight_until_its_end(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 16:30:00', 'UTC'));
        $overnight = $this->booking(['reservation_date' => '2026-10-14', 'start_time' => '23:00', 'end_time' => '01:00']);
        $future = $this->booking();
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('upcomingReservation', fn ($row) => $row->is($overnight))
            ->assertSee('Oct 14, 2026')->assertSee('11:00 PM')->assertSee('1:00 AM')->assertSee('Ends Oct 15, 2026');
        $this->travelTo(Carbon::parse('2026-10-15 01:00:00', 'Asia/Manila'));
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('upcomingReservation', fn ($row) => $row->is($future))
            ->assertDontSee('Ends Oct 15, 2026');
    }

    public function test_ineligible_bookings_are_excluded_while_counts_keep_all_owned_history(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->booking(['status' => $status]);
        }
        $this->booking(['status' => 'pending', 'reservation_date' => '2026-10-01', 'barangay' => 'Taft']);
        $this->booking(['start_time' => '10:00', 'end_time' => '11:00']);
        $this->booking(['start_time' => '11:00', 'end_time' => '12:00']);
        $this->booking(['reservation_date' => '2026-10-14', 'start_time' => '23:00', 'end_time' => '01:00']);
        foreach (['Washington', 'Taft'] as $barangay) {
            $other = User::factory()->create(['barangay' => $barangay]);
            $this->booking(['user_id' => $other->id, 'barangay' => $barangay]);
            $this->booking(['user_id' => $other->id, 'barangay' => $barangay, 'status' => 'pending']);
        }
        $response = $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('upcomingReservation', null)->assertViewHas('pendingCount', 2)->assertViewHas('acceptedCount', 3)
            ->assertSee('No upcoming scheduled reservations.')->assertDontSee('Community Court');
        $this->renderFixture('empty', $response->getContent());
    }

    public function test_empty_account_and_missing_barangay_are_rendered_cleanly(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertViewHas('pendingCount', 0)->assertViewHas('acceptedCount', 0)
            ->assertSee('No upcoming scheduled reservations.');
        $this->booking(['barangay' => '']);
        $this->get(route('dashboard'))->assertOk()->assertSee('Oct 15, 2026')->assertDontSee('·');
    }

    public function test_dashboard_still_requires_authentication_and_verification(): void
    {
        auth()->logout();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }
}
