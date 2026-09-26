<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperAdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 12:00:00'));
        $this->super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservation(array $attributes = []): Reservation
    {
        $reservation = new Reservation;
        $reservation->forceFill(array_merge([
            'user_id' => $this->super->id, 'barangay' => 'Washington',
            'facility_slug' => 'court', 'facility_name' => 'Court', 'category' => 'Facility',
            'location' => 'Washington', 'reservation_date' => '2027-01-10',
            'start_time' => '22:00', 'end_time' => '01:00', 'purpose' => 'Test', 'attendees' => 5,
            'status' => 'pending', 'total_payment' => null, 'hourly_rate_snapshot' => '100.00',
            'created_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-09-01 12:00:00',
        ], $attributes))->save();

        return $reservation;
    }

    public function test_access_requires_verified_super_admin_and_navigation_uses_new_route(): void
    {
        $this->get(route('super-admin.analytics'))->assertRedirect(route('login'));
        foreach (['admin', 'user'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('super-admin.analytics'))->assertForbidden();
        }
        $this->actingAs(User::factory()->unverified()->create(['role' => 'super_admin']))
            ->get(route('super-admin.analytics'))->assertRedirect(route('verification.notice'));
        $this->actingAs($this->super)->get(route('super-admin.analytics'))->assertOk()
            ->assertSee('Super Admin Analytics')->assertDontSee('Resident Management')
            ->assertSee('class="nav-link active" href="'.route('super-admin.analytics').'"', false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('super-admin-analytics.js');
    }

    public function test_system_totals_statuses_and_payments_span_barangays_without_writes(): void
    {
        User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        User::factory()->create(['role' => 'admin', 'barangay' => 'Mabua', 'is_active' => false]);
        User::factory()->count(2)->create(['role' => 'user', 'barangay' => 'Mabua']);
        User::factory()->create(['role' => 'user', 'barangay' => 'Washington', 'is_active' => false]);
        $this->reservation(['status' => 'accepted', 'total_payment' => '1500.25']);
        $this->reservation(['status' => 'accepted', 'total_payment' => '500.50', 'barangay' => 'Mabua']);
        $this->reservation(['status' => 'pending', 'total_payment' => '9000.00']);
        $this->reservation(['status' => 'rejected', 'total_payment' => '8000.00']);
        $this->reservation(['status' => 'accepted', 'total_payment' => null]);
        $writes = [];
        DB::listen(function ($event) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $response = $this->actingAs($this->super)->get(route('super-admin.analytics'))->assertOk();
        $a = $response->viewData('analytics');
        $this->assertSame([2, 2, 3, 5], [$a['totalBarangays'], $a['totalAdmins'], $a['totalResidents'], $a['totalReservations']]);
        $this->assertSame(['Pending' => 1, 'Accepted' => 3, 'Rejected' => 1], $a['statuses']);
        $this->assertSame('2000.75', $a['collected']);
        $this->assertSame(1, $a['unrecorded']);
        $this->assertSame([['barangay' => 'Washington', 'count' => 4], ['barangay' => 'Mabua', 'count' => 1]], $a['byBarangay']);
        $this->assertCount(2, $a['mostUsed']);
        $this->assertCount(9, $a['series']);
        $this->assertSame(['month' => '2026-09', 'count' => 5], $a['series'][8]);
        $this->assertSame([], $writes);
        if ($path = getenv('SUPER_ANALYTICS_RENDER_PATH')) {
            file_put_contents($path, $response->getContent());
        }
    }

    public function test_represented_barangays_include_facilities_and_history_not_the_static_catalog(): void
    {
        Facility::create(['barangay' => 'Ipil', 'slug' => 'hall', 'name' => 'Hall', 'category' => 'Facility',
            'description' => 'Hall', 'capacity' => 20, 'location' => 'Ipil', 'status' => 'Available']);
        $this->reservation(['barangay' => 'Mabua', 'created_at' => '2020-01-01']);
        User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
        $a = $this->actingAs($this->super)->get(route('super-admin.analytics'))->assertOk()->viewData('analytics');
        $this->assertSame(['Ipil', 'Mabua', 'Washington'], $a['barangays']);
        $this->assertSame(2, $a['totalBarangays']);
        $this->assertSame(0, $a['totalReservations']);
    }

    public function test_barangay_filter_updates_all_sections_and_ignores_super_admin_home_barangay(): void
    {
        User::factory()->create(['role' => 'admin', 'barangay' => 'Mabua']);
        User::factory()->count(2)->create(['role' => 'user', 'barangay' => 'Mabua']);
        User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->reservation(['status' => 'accepted', 'total_payment' => '20.25', 'barangay' => 'Mabua']);
        $this->reservation(['status' => 'rejected', 'total_payment' => '90.00']);
        $a = $this->actingAs($this->super)->get(route('super-admin.analytics', ['barangay' => 'Mabua']))->assertOk()->viewData('analytics');
        $this->assertSame([1, 1, 2, 1], [$a['totalBarangays'], $a['totalAdmins'], $a['totalResidents'], $a['totalReservations']]);
        $this->assertSame(['Pending' => 0, 'Accepted' => 1, 'Rejected' => 0], $a['statuses']);
        $this->assertSame('20.25', $a['collected']);
        $this->assertSame([['barangay' => 'Mabua', 'count' => 1]], $a['byBarangay']);
        $this->assertSame($a['byBarangay'], $a['mostActive']);
        $this->assertSame('Mabua', $a['mostUsed'][0]['barangay']);
        $this->assertSame(1, array_sum(array_column($a['series'], 'count')));
    }

    public function test_date_filters_use_submission_dates_and_fill_months_with_zero(): void
    {
        foreach (['2025-12-31 23:59:59', '2026-01-01 00:00:00', '2026-08-31 23:59:59', '2026-09-01 00:00:00', '2026-09-26 23:59:59', '2026-09-27 00:00:00'] as $date) {
            $this->reservation(['created_at' => $date, 'status' => 'accepted', 'total_payment' => '10.00']);
        }
        foreach (['month' => 2, 'year' => 4, 'all' => 6] as $period => $expected) {
            $a = $this->actingAs($this->super)->get(route('super-admin.analytics', ['period' => $period]))->assertOk()->viewData('analytics');
            $this->assertSame($expected, $a['totalReservations']);
            $this->assertSame($expected, $a['statuses']['Accepted']);
            $this->assertSame(number_format($expected * 10, 2, '.', ''), $a['collected']);
            $this->assertSame($expected, $a['byBarangay'][0]['count']);
            $this->assertSame($expected, $a['mostUsed'][0]['count']);
            $this->assertSame($expected, array_sum(array_column($a['series'], 'count')));
            if ($period === 'year') {
                $this->assertSame(['month' => '2026-02', 'count' => 0], $a['series'][1]);
            }
            if ($period === 'all') {
                $this->assertSame('2025-12', $a['series'][0]['month']);
                $this->assertCount(10, $a['series']);
            }
        }
    }

    public function test_all_periods_filter_every_metric_with_inclusive_boundaries(): void
    {
        $dates = ['2025-12-31 23:59:59', '2026-01-01 00:00:00', '2026-08-27 23:59:59',
            '2026-08-28 00:00:00', '2026-09-01 00:00:00', '2026-09-19 23:59:59',
            '2026-09-20 00:00:00', '2026-09-26 23:59:59', '2026-09-27 00:00:00'];
        foreach ($dates as $index => $date) {
            $barangay = 'History '.$index;
            User::factory()->create(['role' => 'admin', 'barangay' => $barangay, 'created_at' => $date]);
            User::factory()->create(['role' => 'user', 'barangay' => $barangay, 'created_at' => $date]);
            $this->reservation(['barangay' => $barangay, 'created_at' => $date, 'status' => 'accepted', 'total_payment' => '10.00']);
        }
        foreach ([['7', '2026-09-20', 2], ['30', '2026-08-28', 5], ['month', '2026-09-01', 4],
            ['year', '2026-01-01', 7], ['custom', '2026-08-28', 4], ['all', null, 9]] as [$period, $start, $expected]) {
            $query = ['analytics_period' => $period];
            if ($period === 'custom') {
                $query += ['analytics_start' => $start, 'analytics_end' => '2026-09-20'];
            }
            $response = $this->actingAs($this->super)->get(route('super-admin.analytics', $query))->assertOk();
            $a = $response->viewData('analytics');
            $this->assertSame($period, $a['period']);
            $this->assertSame($start, $a['start']);
            $this->assertSame($period === 'all' ? null : ($query['analytics_end'] ?? '2026-09-26'), $a['end']);
            $this->assertSame([$expected, $expected, $expected, $expected],
                [$a['totalBarangays'], $a['totalAdmins'], $a['totalResidents'], $a['totalReservations']]);
            $this->assertSame($expected, $a['statuses']['Accepted']);
            $this->assertSame(number_format($expected * 10, 2, '.', ''), $a['collected']);
            $this->assertSame($expected, array_sum(array_column($a['byBarangay'], 'count')));
            $this->assertSame($expected, array_sum(array_column($a['series'], 'count')));
            $this->assertSame(min(5, $expected), count($a['mostActive']));
            $this->assertSame(min(5, $expected), count($a['mostUsed']));
            $response->assertSee('name="analytics_period"', false)
                ->assertSee('name="analytics_start"', false)->assertSee('name="analytics_end"', false);
            // A refresh with the same GET parameters yields the same data.
            $this->assertSame($a, $this->get(route('super-admin.analytics', $query))->viewData('analytics'));
        }
    }

    public function test_custom_range_crosses_months_without_skipping_short_months_and_retains_barangay(): void
    {
        foreach (['2026-01-31 00:00:00', '2026-02-28 23:59:59', '2026-03-01 23:59:59'] as $date) {
            $this->reservation(['created_at' => $date, 'barangay' => 'Mabua']);
            $this->reservation(['created_at' => $date]);
        }
        $query = ['analytics_period' => 'custom', 'analytics_start' => '2026-01-31', 'analytics_end' => '2026-03-01', 'barangay' => 'Mabua'];
        $a = $this->actingAs($this->super)->get(route('super-admin.analytics', $query))->assertOk()->viewData('analytics');
        $this->assertSame(3, $a['totalReservations']);
        $this->assertSame([['month' => '2026-01', 'count' => 1], ['month' => '2026-02', 'count' => 1], ['month' => '2026-03', 'count' => 1]], $a['series']);
        $this->assertSame([['barangay' => 'Mabua', 'count' => 3]], $a['byBarangay']);
        $query['analytics_start'] = $query['analytics_end'];
        $a = $this->get(route('super-admin.analytics', $query))->assertOk()->viewData('analytics');
        $this->assertSame(1, $a['totalReservations']);
        $this->assertCount(1, $a['series']);
    }

    public static function invalidCustomRanges(): array
    {
        return [
            [['analytics_period' => 'bad'], 'analytics_period'],
            [['analytics_period' => 'custom'], 'analytics_start'],
            [['analytics_start' => '2026-09-21', 'analytics_end' => '2026-09-20'], 'analytics_end'],
            [['analytics_start' => '2026-02-30', 'analytics_end' => '2026-09-20'], 'analytics_start'],
            [['analytics_start' => '2026-09-20', 'analytics_end' => '2026-09-27'], 'analytics_end'],
            [['analytics_start' => '2024-01-01', 'analytics_end' => '2026-09-20'], 'analytics_end'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCustomRanges')]
    public function test_custom_validation_matches_admin_and_displays_errors(array $query, string $field): void
    {
        $query += ['analytics_period' => 'custom'];
        $this->actingAs($this->super)->from(route('super-admin.analytics'))
            ->get(route('super-admin.analytics', $query))
            ->assertRedirect(route('super-admin.analytics'))->assertSessionHasErrors($field);
        // Carry the flashed errors into the simulated browser's next request.
        $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('super-admin.analytics'))->assertOk()->assertSee('role="alert"', false);
    }

    public function test_filter_fields_display_the_shared_range_and_keep_barangay_selection(): void
    {
        User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        foreach (['7', '30', 'month', 'year', 'custom', 'all'] as $period) {
            $query = ['analytics_period' => $period, 'barangay' => 'Washington'];
            if ($period === 'custom') {
                $query += ['analytics_start' => '2026-08-01', 'analytics_end' => '2026-08-31'];
            }
            $response = $this->actingAs($this->super)->get(route('super-admin.analytics', $query))->assertOk();
            $a = $response->viewData('analytics');
            $response->assertSee('Start date')->assertSee('End date')->assertDontSee('Custom start')->assertDontSee('Custom end');
            foreach (['start', 'end'] as $field) {
                preg_match('/<input id="super-analytics-'.$field.'"[^>]*>/', $response->getContent(), $match);
                $this->assertStringContainsString('value="'.($a[$field] ?? '').'"', $match[0]);
                $this->assertStringContainsString('max="2026-09-26"', $match[0]);
                $this->assertSame($period !== 'custom', str_contains($match[0], 'disabled'));
                $this->assertSame($period === 'custom', str_contains($match[0], 'required'));
            }
            $this->assertMatchesRegularExpression('/value="Washington"\s+selected/', $response->getContent());
        }
    }

    public function test_preset_display_dates_match_query_dates_across_leap_year_boundaries(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-03-01 00:30:00'));
        $expected = ['7' => '2024-02-24', '30' => '2024-02-01', 'month' => '2024-03-01', 'year' => '2024-01-01', 'all' => ''];
        foreach (\App\Support\AnalyticsPeriod::presets() as $period => $dates) {
            $a = $this->actingAs($this->super)->get(route('super-admin.analytics', ['analytics_period' => (string) $period]))
                ->assertOk()->viewData('analytics');
            $this->assertSame($expected[$period], $dates['start']);
            $this->assertSame($a['start'] ?? '', $dates['start']);
            $this->assertSame($a['end'] ?? '', $dates['end']);
        }
    }

    public function test_empty_data_and_invalid_filters(): void
    {
        foreach (['7', '30', 'year', 'month', 'all'] as $period) {
            $response = $this->actingAs($this->super)->get(route('super-admin.analytics', ['period' => $period]))->assertOk()
                ->assertSee('No reservation data available for the selected period.');
            $a = $response->viewData('analytics');
            $this->assertSame([0, 0, 0, 0], [$a['totalBarangays'], $a['totalAdmins'], $a['totalResidents'], $a['totalReservations']]);
            $this->assertSame([], $a['byBarangay']);
            $this->assertSame([], $a['mostUsed']);
            $this->assertSame('0.00', $a['collected']);
            $this->assertSame(0, array_sum(array_column($a['series'], 'count')));
        }
        foreach ([['period' => 'bad'], ['barangay' => 'Unknown'], ['barangay' => ['Washington']]] as $input) {
            $this->from(route('super-admin.analytics'))->get(route('super-admin.analytics', $input))
                ->assertRedirect(route('super-admin.analytics'))->assertSessionHasErrors(array_key_first($input));
        }
    }

    public function test_rankings_limit_to_five_and_preserve_deleted_facility_snapshots(): void
    {
        foreach (['Washington', 'Mabua', 'Ipil', 'Lipata', 'Taft', 'Alegria'] as $index => $barangay) {
            for ($i = 0; $i <= $index; $i++) {
                $this->reservation(['barangay' => $barangay, 'facility_name' => 'Historical '.$barangay, 'facility_id' => null]);
            }
        }
        $a = $this->actingAs($this->super)->get(route('super-admin.analytics'))->assertOk()->viewData('analytics');
        $this->assertCount(6, $a['byBarangay']);
        $this->assertCount(5, $a['mostActive']);
        $this->assertCount(5, $a['mostUsed']);
        $this->assertSame(['barangay' => 'Alegria', 'count' => 6], $a['mostActive'][0]);
        $this->assertSame('Historical Alegria', $a['mostUsed'][0]['name']);
    }
}
