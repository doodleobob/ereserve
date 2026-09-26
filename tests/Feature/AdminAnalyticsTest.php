<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 12:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservation(array $overrides = []): Reservation
    {
        $reservation = Reservation::create(array_merge([
            'user_id' => $this->resident->id, 'barangay' => 'Washington',
            'facility_id' => null, 'facility_slug' => 'court', 'facility_name' => 'Court',
            'category' => 'Facility', 'location' => 'Washington',
            'reservation_date' => '2026-10-12', 'start_time' => '22:00', 'end_time' => '01:00',
            'purpose' => 'Analytics test', 'attendees' => 10, 'status' => 'pending',
            'hourly_rate_snapshot' => '100.00', 'total_payment' => '300.00',
            'created_at' => '2026-09-25 10:00:00', 'updated_at' => '2026-09-26 10:00:00',
        ], $overrides));

        $reservation->forceFill([
            'created_at' => $overrides['created_at'] ?? '2026-09-25 10:00:00',
            'updated_at' => $overrides['updated_at'] ?? '2026-09-26 10:00:00',
        ])->save();

        return $reservation;
    }

    public function test_admin_metrics_use_scoped_requests_and_only_accepted_payments(): void
    {
        $this->reservation(['status' => 'accepted', 'total_payment' => '1500.25']);
        $this->reservation(['status' => 'accepted', 'total_payment' => '500.50', 'facility_slug' => 'chairs', 'facility_name' => 'Chairs', 'category' => 'Equipment']);
        $this->reservation(['total_payment' => '9000.00']);
        $this->reservation(['status' => 'rejected', 'total_payment' => '8000.00']);
        $this->reservation(['barangay' => 'Taft', 'facility_name' => 'Secret Taft Hall', 'status' => 'accepted', 'total_payment' => '99999.00']);
        $this->reservation(['created_at' => '2026-08-01 00:00:00', 'status' => 'accepted', 'total_payment' => '750.00']);
        $before = Reservation::orderBy('id')->get()->toArray();

        $response = $this->actingAs($this->admin)->get(route('analytics', ['barangay' => 'Taft', 'analytics_period' => '7']));
        $response->assertOk()->assertSee('Admin Analytics')
            ->assertSee('Booked Reservations')->assertSee('₱2,000.75')
            ->assertDontSee('Secret Taft Hall');
        $a = $response->viewData('analytics');
        $this->assertSame([4, 1, 2, 1, '2000.75'], [$a['requests'], $a['pending'], $a['booked'], $a['rejected'], $a['collected']]);
        $this->assertSame(['name' => 'Court', 'barangay' => 'Washington', 'category' => 'Facility', 'count' => 3], $a['mostRequested'][0]);
        $this->assertSame(2, array_sum(array_column($a['mostBooked'], 'count')));
        $this->assertSame(7, count($a['series']));
        $this->assertSame(['date' => '2026-09-25', 'requests' => 4, 'collected' => '2000.75'], $a['series'][5]);
        $this->assertSame(['date' => '2026-09-26', 'requests' => 0, 'collected' => '0.00'], $a['series'][6]);
        // Existing summary cards remain all-time, independent of analytics dates.
        $this->get(route('dashboard'))->assertOk()->assertViewHas('acceptedCount', 3)
            ->assertViewHas('pendingCount', 1)->assertViewHas('rejectedCount', 1);
        $this->assertSame($before, Reservation::orderBy('id')->get()->toArray());
        if ($path = getenv('ANALYTICS_RENDER_PATH')) {
            file_put_contents($path, $response->getContent());
        }
    }

    public function test_super_admin_is_redirected_to_dedicated_system_analytics(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->get(route('analytics'))->assertRedirect(route('super-admin.analytics'));
    }

    public function test_residents_and_guests_cannot_receive_analytics(): void
    {
        $this->get(route('analytics'))->assertRedirect(route('login'));
        $this->actingAs($this->resident)->get(route('analytics', ['analytics_period' => '7', 'barangay' => 'Taft']))
            ->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('admin-analytics-data')
            ->assertDontSee('href="'.route('analytics').'"', false);
        $unverified = User::factory()->unverified()->create(['role' => 'admin']);
        $this->actingAs($unverified)->get(route('analytics'))->assertRedirect(route('verification.notice'));
    }

    public function test_presets_and_custom_dates_use_submission_dates_with_inclusive_boundaries(): void
    {
        foreach (['2026-08-27 23:59:59', '2026-08-28 00:00:00', '2026-09-01 00:00:00', '2026-09-19 23:59:59', '2026-09-20 00:00:00', '2026-09-26 23:59:59', '2026-09-27 00:00:00'] as $date) {
            $this->reservation(['created_at' => $date, 'status' => 'accepted']);
        }
        foreach (['7' => 2, '30' => 5, 'month' => 4] as $period => $expected) {
            $a = $this->actingAs($this->admin)->get(route('analytics', ['analytics_period' => (string) $period]))->assertOk()->viewData('analytics');
            $this->assertSame($expected, $a['requests']);
            $this->assertSame($expected, $a['booked']);
            $this->assertSame(number_format($expected * 300, 2, '.', ''), $a['collected']);
        }
        $a = $this->get(route('analytics', ['analytics_period' => 'custom', 'analytics_start' => '2026-09-20', 'analytics_end' => '2026-09-20']))->assertOk()->viewData('analytics');
        $this->assertSame(1, $a['requests']);
        $this->assertCount(1, $a['series']);
        $this->assertSame('300.00', $a['series'][0]['collected']);
    }

    public function test_invalid_periods_and_custom_ranges_are_rejected(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            [['analytics_period' => 'invalid'], 'analytics_period'],
            [['analytics_period' => 'custom'], 'analytics_start'],
            [['analytics_period' => 'custom', 'analytics_start' => '2026-09-21', 'analytics_end' => '2026-09-20'], 'analytics_end'],
            [['analytics_period' => 'custom', 'analytics_start' => '2026-02-30', 'analytics_end' => '2026-09-20'], 'analytics_start'],
            [['analytics_period' => 'custom', 'analytics_start' => '2026-09-21', 'analytics_end' => '2026-09-27'], 'analytics_end'],
            [['analytics_period' => 'custom', 'analytics_start' => '2024-01-01', 'analytics_end' => '2026-09-20'], 'analytics_end'],
        ] as [$query, $field]) {
            $this->from(route('analytics'))->get(route('analytics', $query))
                ->assertRedirect(route('analytics'))->assertSessionHasErrors($field);
        }
    }

    public function test_empty_period_and_unrecorded_or_zero_payments_are_explicit(): void
    {
        $response = $this->actingAs($this->admin)->get(route('analytics'));
        $response->assertOk()->assertSee('No reservation data available for this period.')->assertSee('₱0.00');
        $a = $response->viewData('analytics');
        $this->assertSame([0, 0, 0, 0, '0.00'], [$a['requests'], $a['booked'], $a['pending'], $a['rejected'], $a['collected']]);
        $this->assertSame([], $a['mostBooked']);
        $this->reservation(['status' => 'accepted', 'total_payment' => null]);
        $this->reservation(['status' => 'accepted', 'total_payment' => '0.00']);
        $response = $this->get(route('analytics'))->assertOk()->assertSee('1 booked reservation(s) have no recorded payment');
        $this->assertSame(2, $response->viewData('analytics')['booked']);
        $this->assertSame('0.00', $response->viewData('analytics')['collected']);
    }

    public function test_overnight_and_deleted_facilities_remain_in_booking_utilization(): void
    {
        $r = $this->reservation(['status' => 'accepted', 'facility_name' => 'Deleted Court']);
        $this->assertSame(180, $r->durationMinutes());
        $response = $this->actingAs($this->admin)->get(route('analytics'))->assertOk()->assertSee('Facility / Equipment Utilization')->assertSee('Deleted Court');
        $this->assertSame(1, $response->viewData('analytics')['mostBooked'][0]['count']);
        $this->assertNull($r->fresh()->facility_id);
    }

    public function test_existing_available_card_excludes_unavailable_facilities_and_recent_list_is_limited(): void
    {
        foreach (['Available', 'Unavailable'] as $status) {
            Facility::create(['barangay' => 'Washington', 'slug' => strtolower($status), 'name' => $status.' Hall', 'category' => 'Facility', 'description' => 'Hall', 'capacity' => 10, 'location' => 'Washington', 'status' => $status]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->reservation();
        }
        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertViewHas('facilityCount', 1)->assertViewHas('pendingCount', 6);
        $this->assertCount(4, $response->viewData('recentReservations'));
    }

    public function test_analytics_has_its_own_page_and_navigation_without_loading_on_dashboard(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $this->admin->forceFill(['role' => $role])->save();
            $this->actingAs($this->admin);
            $dashboard = $this->get(route('dashboard', ['analytics_period' => 'invalid']))->assertOk()
                ->assertSee($role === 'admin' ? 'Available Facilities' : 'Total Barangays')->assertSee('Recent Reservations')->assertDontSee('Quick Actions')
                ->assertDontSee('id="admin-analytics"', false)->assertDontSee('chart.umd.min.js')
                ->assertDontSee('admin-analytics.js')->assertDontSee('admin-analytics.css');
            $this->assertArrayNotHasKey('analytics', $dashboard->viewData());
            $expectedNavigation = $role === 'admin'
                ? ['Admin Dashboard', 'Reservation Management', 'Facility Management', 'Resident Management', 'Analytics', 'Profile']
                : ['Super Admin Dashboard', 'Reservation Management', 'Facility Management', 'Analytics', 'Profile', 'Admin Management'];
            $analyticsRoute = $role === 'super_admin' ? 'super-admin.analytics' : 'analytics';
            $analyticsId = $role === 'super_admin' ? 'super-admin-analytics' : 'admin-analytics';
            $page = $this->get(route($analyticsRoute))->assertOk()->assertViewIs($role === 'super_admin' ? 'super-admin-analytics' : 'analytics')
                ->assertSeeInOrder($expectedNavigation)
                ->assertSee('class="nav-link active" href="'.route($analyticsRoute).'"', false)
                ->assertSee('action="'.route($analyticsRoute).($role === 'admin' ? '#admin-analytics' : '').'"', false);
            $this->assertSame(1, substr_count($page->getContent(), 'id="'.$analyticsId.'"'));
            $this->assertSame(1, substr_count($page->getContent(), 'js/'.$analyticsId.'.js'));
            $this->assertSame(1, substr_count($page->getContent(), 'js/vendor/chart.umd.min.js'));
            preg_match('/<nav class="user-nav">(.*?)<\/nav>/s', $page->getContent(), $nav);
            preg_match_all('/<a\b[^>]*>(.*?)<\/a>/s', $nav[1], $links);
            $labels = array_map(fn ($link) => trim(strip_tags($link)), $links[1]);
            $this->assertSame($expectedNavigation, $labels);
        }
    }
}
