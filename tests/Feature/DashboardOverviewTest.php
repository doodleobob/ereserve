<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function facility(string $barangay, string $status = 'Available', string $category = 'Facility'): Facility
    {
        return Facility::create(['barangay' => $barangay, 'slug' => 'resource-'.Facility::count(),
            'name' => $barangay.' '.$category, 'category' => $category, 'description' => 'Resource',
            'capacity' => 20, 'location' => $barangay, 'status' => $status]);
    }

    private function reservation(User $owner, array $overrides = []): Reservation
    {
        $reservation = Reservation::create(array_merge([
            'user_id' => $owner->id, 'barangay' => $owner->barangay,
            'facility_slug' => 'court', 'facility_name' => $owner->barangay.' Court', 'category' => 'Facility',
            'location' => $owner->barangay, 'reservation_date' => today()->toDateString(),
            'start_time' => '13:00', 'end_time' => '14:00', 'purpose' => 'Test', 'attendees' => 5, 'status' => 'pending',
        ], $overrides));
        if (isset($overrides['created_at'])) {
            $reservation->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $reservation;
    }

    private function renderFixture(string $role, string $html): void
    {
        if ($directory = getenv('DASHBOARD_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$role.'.html', $html);
        }
    }

    public function test_admin_overview_is_barangay_scoped_and_uses_existing_availability_and_user_relationship(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $resident = User::factory()->create(['name' => 'Maria Resident', 'barangay' => 'Washington']);
        $outside = User::factory()->create(['name' => 'Private Taft Resident', 'barangay' => 'Taft']);
        $available = $this->facility('Washington');
        $this->facility('Washington', 'Available', 'Equipment');
        $this->facility('Washington', 'Unavailable');
        $this->facility('Taft');
        $this->reservation($resident, ['status' => 'accepted', 'facility_id' => $available->id, 'start_time' => '11:00', 'end_time' => '13:00']);
        $this->reservation($resident, ['status' => 'pending']);
        $this->reservation($resident, ['status' => 'rejected']);
        $this->reservation($outside, ['status' => 'accepted']);
        $before = Reservation::orderBy('id')->get()->toArray();
        $response = $this->actingAs($admin)->get(route('dashboard', ['barangay' => 'Taft', 'facility' => 'anything', 'analytics_period' => 'invalid']))
            ->assertOk()->assertSee('Overview of facilities and reservation activity')->assertDontSee('Quick Actions')
            ->assertSee("Today's Reservations", false)->assertSee('Recent Reservations')->assertSee('Maria Resident')
            ->assertDontSee('Private Taft Resident')->assertDontSee('Taft Court')->assertDontSee('Unknown user')
            ->assertDontSee('chart.umd.min.js')->assertViewHas('facilityCount', 2)
            ->assertViewHas('pendingCount', 1)->assertViewHas('acceptedCount', 1)->assertViewHas('rejectedCount', 1);
        $this->assertCount(3, $response->viewData('todaysReservations'));
        $this->assertTrue($response->viewData('recentReservations')->every(fn ($r) => $r->relationLoaded('user') && $r->user->id === $resident->id));
        $this->assertSame($before, Reservation::orderBy('id')->get()->toArray());
        $this->renderFixture('admin', $response->getContent());
    }

    public function test_today_includes_overnight_overlap_and_prioritizes_current_then_upcoming(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $resident = User::factory()->create(['barangay' => 'Washington']);
        $overnight = $this->reservation($resident, ['reservation_date' => '2026-09-25', 'start_time' => '23:00', 'end_time' => '01:00', 'status' => 'accepted']);
        $current = $this->reservation($resident, ['start_time' => '11:00', 'end_time' => '13:00', 'status' => 'accepted']);
        $upcoming = $this->reservation($resident, ['start_time' => '14:00', 'end_time' => '15:00']);
        $this->reservation($resident, ['reservation_date' => '2026-09-25', 'start_time' => '22:00', 'end_time' => '00:00']);
        $this->reservation($resident, ['reservation_date' => '2026-09-27']);
        $a = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('todaysReservations');
        $this->assertSame([$current->id, $upcoming->id, $overnight->id], $a->modelKeys());
    }

    public function test_super_admin_counts_and_activity_are_system_wide(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Home Only']);
        User::factory()->create(['role' => 'admin', 'name' => 'Washington Admin', 'barangay' => 'Washington']);
        User::factory()->create(['role' => 'admin', 'barangay' => 'Taft', 'is_active' => false]);
        $resident = User::factory()->create(['name' => 'Washington Resident', 'barangay' => 'Washington']);
        $outside = User::factory()->create(['name' => 'Taft Resident', 'barangay' => 'Taft']);
        $this->facility('Ipil', 'Unavailable');
        $this->facility('Washington', 'Available', 'Equipment');
        $this->reservation($resident);
        $this->reservation($outside, ['status' => 'accepted']);
        $response = $this->actingAs($super)->get(route('dashboard', ['barangay' => 'Washington']))->assertOk()
            ->assertSee('System-wide overview of eReserve operations')->assertSee('Total Barangays')->assertSee('Total Admins')
            ->assertSee('Total Facilities &amp; Equipment', false)->assertSee('Total Reservations')->assertSee('Recent System Activity')
            ->assertSee('Washington Resident')->assertSee('Taft Resident')->assertDontSee('Unknown user')->assertDontSee('Quick Actions')
            ->assertDontSee('Available Facilities')->assertDontSee('Pending Requests')->assertDontSee('chart.umd.min.js')
            ->assertViewHas('totalBarangays', 3)->assertViewHas('totalAdmins', 2)->assertViewHas('totalFacilities', 2)->assertViewHas('totalReservations', 2);
        $this->assertCount(6, $response->viewData('recentActivity'));
        $this->assertEqualsCanonicalizing(['Admin account created', 'Facility / equipment created', 'Reservation updated'],
            $response->viewData('recentActivity')->pluck('title')->unique()->all());
        $this->assertEqualsCanonicalizing(['Washington', 'Taft'], $response->viewData('recentReservations')->pluck('barangay')->all());
        $this->renderFixture('super_admin', $response->getContent());
    }

    public function test_recent_lists_are_limited_and_unknown_user_is_only_used_for_a_missing_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $resident = User::factory()->create(['name' => 'Known Resident', 'barangay' => 'Washington']);
        for ($i = 0; $i < 8; $i++) {
            $this->reservation($resident, ['created_at' => now()->subMinutes(10 - $i)]);
        }
        $orphan = $this->reservation($resident, ['user_id' => 999999]);
        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Known Resident')->assertSee('Unknown user');
        $this->assertCount(4, $response->viewData('recentReservations'));
        $this->assertCount(6, $response->viewData('todaysReservations'));
        $this->assertSame($orphan->id, $response->viewData('recentReservations')->first()->id);
        $this->assertNull($response->viewData('recentReservations')->first()->user);
    }

    public function test_empty_overviews_and_existing_access_rules(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create(['role' => 'admin']))->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertViewHas('facilityCount', 0)
            ->assertSee('No reservations scheduled for today.')->assertSee('No reservations yet.');
        $this->actingAs(User::factory()->create(['role' => 'user']))->get(route('dashboard'))->assertOk()
            ->assertSee('Reservation Calendar')->assertDontSee('Recent System Activity');
    }
}
