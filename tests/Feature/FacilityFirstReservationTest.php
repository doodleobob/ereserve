<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FacilityFirstReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $this->facility = Facility::create(['name' => 'Taft Hall', 'slug' => 'taft-hall', 'barangay' => 'Taft', 'category' => 'Facility', 'description' => 'Community hall', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'reservation_access' => 'all_registered_users', 'hourly_rate' => 100]);
        $this->actingAs($this->resident);
    }

    private function details(array $query = []): string
    {
        return route('facilities.show', ['slug' => $this->facility->slug] + $query);
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_merge(['user_id' => $this->resident->id, 'facility_id' => $this->facility->id, 'facility_slug' => $this->facility->slug, 'facility_name' => $this->facility->name, 'barangay' => 'Taft', 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => '2026-10-16', 'start_time' => '08:00', 'end_time' => '09:00', 'purpose' => 'Community gathering', 'attendees' => 10, 'status' => 'pending'], $overrides));
    }

    public function test_dashboard_counts_only_the_residents_reservations_across_barangays(): void
    {
        $this->booking();
        $this->booking(['status' => 'accepted']);
        $this->booking(['status' => 'accepted', 'reservation_date' => '2026-10-14']);
        $this->booking(['status' => 'rejected']);
        $this->booking(['user_id' => User::factory()->create()->id, 'facility_name' => 'Private other booking']);
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboards.resident')
            ->assertViewHas('pendingCount', 1)->assertViewHas('acceptedCount', 2)
            ->assertViewHas('upcomingReservations', fn ($rows) => $rows->count() === 2)
            ->assertSee(route('facilities'))->assertSee(route('calendar'))->assertDontSee('calendar-grid')
            ->assertDontSee('Private other booking');
    }

    public function test_old_dashboard_links_preserve_context_without_redirect_loops(): void
    {
        $query = ['barangay' => 'Taft', 'month' => '2026-11', 'date' => '2026-11-02', 'start_time' => '23:17', 'end_time' => '00:09'];
        foreach (['facility', 'resource'] as $key) {
            $this->get(route('dashboard', [$key => $this->facility->slug] + $query))->assertRedirect($this->details($query));
        }
        $this->get($this->details($query))->assertOk()->assertSee('name="start_time" value="23:17"', false)->assertSee('Ends Nov 3, 2026');
        $this->get(route('dashboard', ['barangay' => 'Taft', 'date' => '2026-11-02']))
            ->assertRedirect(route('calendar', ['barangay' => 'Taft', 'date' => '2026-11-02']));
        $this->followingRedirects()->get(route('dashboard', ['barangay' => 'all', 'facility' => 'all']))->assertOk()->assertViewIs('calendar.index');
    }

    public function test_resource_is_fixed_and_month_navigation_uses_the_same_details_page(): void
    {
        $response = $this->get($this->details(['date' => '2026-10-16', 'barangay' => 'Washington', 'facility' => 'missing']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertViewHas('selectedBarangay', 'Taft')
            ->assertViewHas('selectedFacility', fn ($resource) => $resource['id'] === $this->facility->id)
            ->assertDontSee('Calendar filters')->assertDontSee('Submit Reservation');
        $this->assertSame(1, substr_count($response->getContent(), 'class="calendar-grid"'));
        $this->get($this->details(['month' => '2026-11']))->assertOk()->assertSee('November 2026')
            ->assertViewHas('calendarDays', fn ($days) => $days->count() === 30);
    }

    public function test_past_unbooked_slots_are_disabled_but_accepted_history_stays_visible(): void
    {
        $this->booking(['reservation_date' => '2026-10-15', 'status' => 'accepted', 'purpose' => 'Private purpose']);
        $this->get($this->details(['date' => '2026-10-15']))->assertOk()->assertSee('schedule-slot-past')->assertSee('Past')
            ->assertSee('8:00 AM - 9:00 AM')->assertSee('Booked')->assertDontSee('Private purpose')
            ->assertViewHas('schedule', fn ($rows) => $rows->firstWhere('start_time', '09:00')['status'] === 'past' && $rows->firstWhere('start_time', '11:00')['status'] === 'available');
        foreach ([['date' => '2026-10-14', 'start_time' => '23:00'], ['date' => '2026-10-15', 'start_time' => '10:30']] as $query) {
            $this->get($this->details($query + ['end_time' => '11:00']))->assertOk()->assertViewHas('selectedSlot', null)->assertDontSee('Submit Reservation');
        }
    }

    public function test_custom_overnight_selection_submits_pending_and_existing_admin_approval_works(): void
    {
        $query = ['date' => '2026-10-31', 'start_time' => '23:17', 'end_time' => '00:09'];
        $this->get($this->details($query))->assertOk()->assertSee('Ends Nov 1, 2026')->assertSee('Submit Reservation');
        $this->post(route('reservations.store', $this->facility->slug), ['reservation_date' => $query['date'], 'start_time' => $query['start_time'], 'end_time' => $query['end_time'], 'purpose' => 'Night event', 'attendees' => 20])
            ->assertRedirect($this->details(['date' => $query['date']]))->assertSessionHasNoErrors();
        $reservation = Reservation::firstOrFail();
        $this->assertSame('pending', $reservation->status);
        $this->assertSame(52, $reservation->durationMinutes());
        $this->get($this->details($query))->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty());
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($admin)->post(route('reservations.accept', $reservation), ['total_payment' => '100.00', 'payment_confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->actingAs($this->resident)->get($this->details(['date' => '2026-11-01']))->assertOk()->assertSee('Continued from Oct 31, 2026');
    }

    public function test_official_use_blocks_custom_selection_across_month_boundary_without_exposing_details(): void
    {
        OfficialUse::create(['facility_id' => $this->facility->id, 'barangay' => 'Taft', 'date' => '2026-11-01', 'start_time' => '00:00', 'end_time' => '02:00', 'purpose' => 'Private official meeting', 'status' => 'active']);
        $this->get($this->details(['date' => '2026-10-31', 'start_time' => '23:17', 'end_time' => '00:09']))->assertOk()
            ->assertViewHas('selectedSlot', null)->assertSee('due to Official Use')->assertDontSee('Submit Reservation')->assertDontSee('Private official meeting');
    }

    public function test_central_calendar_passes_valid_selection_to_details_and_contains_no_reservation_form(): void
    {
        $query = ['barangay' => 'Taft', 'month' => '2026-10', 'date' => '2026-10-16', 'start_time' => '13:17', 'end_time' => '14:23'];
        $this->get(route('calendar', ['facility' => $this->facility->slug] + $query))->assertOk()
            ->assertSee($this->details($query))->assertSee('View Facility')->assertDontSee('Submit Reservation');
    }

    public function test_form_validation_keeps_selection_and_entered_values(): void
    {
        $url = $this->details(['date' => '2026-10-16', 'start_time' => '13:17', 'end_time' => '14:23']);
        $this->from($url)->post(route('reservations.store', $this->facility->slug), ['reservation_date' => '2026-10-16', 'start_time' => '13:17', 'end_time' => '14:23', 'purpose' => 'Keep this purpose', 'attendees' => 100])
            ->assertRedirect($url)->assertSessionHasErrors('attendees');
        $this->get($url)->assertOk()->assertSee('Keep this purpose')->assertSee('name="start_time" value="13:17"', false)->assertSee('value="100"', false);
    }

    public function test_rendered_resident_pages_have_one_reservation_entry_point(): void
    {
        $query = ['date' => '2026-10-16', 'start_time' => '23:17', 'end_time' => '00:09'];
        $pages = ['dashboard' => route('dashboard'), 'listing' => route('facilities', ['barangay' => 'Taft']),
            'details' => $this->details($query), 'schedules' => route('calendar', ['facility' => $this->facility->slug] + $query)];
        foreach ($pages as $name => $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSame($name === 'details' ? 1 : 0, substr_count($response->getContent(), 'Submit Reservation'));
            if ($directory = getenv('FACILITY_FIRST_RENDER_DIR')) {
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        }
    }
}
