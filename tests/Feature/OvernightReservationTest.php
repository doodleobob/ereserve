<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Support\FacilityCatalog;
use App\Support\ReservationAvailability;
use App\Support\ReservationPeriod;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OvernightReservationTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(Carbon::parse('2026-09-25 08:00:00'));
        $this->facility = Facility::create([
            'barangay' => 'Washington', 'slug' => 'court', 'name' => 'Covered Court',
            'category' => 'Facility', 'description' => 'Court', 'capacity' => 50,
            'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => '50.00',
        ]);
    }

    public static function timeRanges(): array
    {
        return [
            ['08:00', '09:00', 60, '2026-09-28'],
            ['13:00', '16:00', 180, '2026-09-28'],
            ['18:00', '21:00', 180, '2026-09-28'],
            ['22:00', '00:00', 120, '2026-09-29'],
            ['22:00', '01:00', 180, '2026-09-29'],
            ['23:00', '02:00', 180, '2026-09-29'],
            ['23:30', '00:30', 60, '2026-09-29'],
        ];
    }

    #[DataProvider('timeRanges')]
    public function test_submission_duration_and_rate_calculation(string $start, string $end, int $minutes, string $endDate): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('reservations.store', 'court'), [
            'reservation_date' => '2026-09-28', 'start_time' => $start, 'end_time' => $end,
            'purpose' => 'Community event', 'attendees' => 5,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $r = Reservation::firstOrFail();
        $this->assertSame($minutes, $r->durationMinutes());
        $this->assertSame($endDate, $r->period()->end->toDateString());
        $this->assertSame(number_format(50 * $minutes / 60, 2, '.', ''), $r->calculatedAmount());
        $this->get(route('reservations.index'))->assertSee('₱50.00 / hour')->assertDontSee('Total Payment');
    }

    public function test_equal_times_are_invalid_and_errors_render_under_the_input(): void
    {
        $user = User::factory()->create();
        $url = route('facilities.show', 'court');
        $this->actingAs($user)->from($url)->post(route('reservations.store', 'court'), [
            'reservation_date' => '2026-09-28', 'start_time' => '22:00', 'end_time' => '22:00',
            'purpose' => 'Event', 'attendees' => 5,
        ])->assertSessionHasErrors('end_time')->assertRedirect($url);
        $this->assertDatabaseCount('reservations', 0);
        $this->withViewErrors(['end_time' => 'End Time must be different'])
            ->view('facility-show', ['facility' => FacilityCatalog::findForUser('court', $user)])
            ->assertSeeInOrder(['id="start_time"', 'id="end_time"', 'End Time must be different'], false);
    }

    private function booking(array $attributes = []): Reservation
    {
        return Reservation::create([
            'user_id' => User::factory()->create()->id, 'facility_id' => $this->facility->id,
            'barangay' => 'Washington', 'facility_slug' => 'court', 'facility_name' => 'Covered Court',
            'category' => 'Facility', 'location' => 'Washington', 'reservation_date' => '2026-09-28',
            'start_time' => '22:00', 'end_time' => '01:00', 'purpose' => 'Event', 'attendees' => 5,
            'status' => 'accepted', 'hourly_rate_snapshot' => '50.00', 'total_payment' => '150.00', ...$attributes,
        ]);
    }

    public function test_calendar_represents_both_days_and_continuation_can_be_selected(): void
    {
        $r = $this->booking();
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard', ['facility' => 'court', 'month' => '2026-09', 'date' => '2026-09-28']))
            ->assertOk()->assertSee('10:00 PM - 1:00 AM')->assertSee('Ends Sep 29, 2026')
            ->assertSee('September 29, 2026: Partially Booked');
        $this->get(route('dashboard', ['facility' => 'court', 'month' => '2026-09', 'date' => '2026-09-29', 'start_time' => '00:00', 'end_time' => '01:00']))
            ->assertOk()->assertSee('12:00 AM - 1:00 AM')->assertSee('Continued from Sep 28, 2026')
            ->assertSee('name="start_time" value="00:00"', false);
        $overlap = new ReservationPeriod('2026-09-29', '00:30', '02:00');
        $this->assertTrue($r->period()->overlaps($overlap->start, $overlap->end));
        // A different resident may still request a conflicting interval.
        $this->post(route('reservations.store', 'court'), [
            'reservation_date' => '2026-09-29', 'start_time' => '00:30', 'end_time' => '02:00',
            'purpose' => 'Another event', 'attendees' => 5,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_in_use_covers_both_dates_and_excludes_the_end_boundary(): void
    {
        $r = $this->booking();
        $user = User::factory()->create();
        foreach (['2026-09-28 22:00', '2026-09-28 23:00', '2026-09-29 00:30'] as $time) {
            $this->travelTo(Carbon::parse($time));
            $this->assertSame('in_use', ReservationAvailability::displayStatus($r));
            $this->assertSame('Currently in Use', FacilityCatalog::findForUser('court', $user)['display_status']);
        }
        foreach (['2026-09-28 21:59', '2026-09-29 01:00', '2026-09-29 01:01'] as $time) {
            $this->travelTo(Carbon::parse($time));
            $this->assertSame('booked', ReservationAvailability::displayStatus($r));
            $this->assertSame('Available', FacilityCatalog::findForUser('court', $user)['display_status']);
        }
        $this->travelTo(Carbon::parse('2026-09-29 00:30'));
        $this->facility->update(['status' => 'Unavailable']);
        $this->assertSame('Unavailable', FacilityCatalog::findForUser('court', $user)['display_status']);
        $this->assertSame('unavailable', ReservationAvailability::monthCalendar($this->facility->id, today(), 'Washington', false)->firstWhere('date', today())['status']);
    }

    public function test_overnight_occupancy_and_month_boundary_use_full_intervals(): void
    {
        $r = $this->booking(['reservation_date' => '2026-09-30', 'end_time' => '09:00']);
        $october = Carbon::parse('2026-10-01');
        $this->assertCount(1, ReservationAvailability::acceptedReservations($this->facility->id, $october, $october, 'Washington'));
        $schedule = ReservationAvailability::daySchedule($this->facility->id, $october, 'Washington');
        $this->assertSame('booked', $schedule->firstWhere('start_time', '08:00')['status']);
        $this->assertSame('available', $schedule->firstWhere('start_time', '09:00')['status']);
        $this->actingAs(User::factory()->create())->get(route('dashboard', ['facility' => 'court', 'month' => '2026-10', 'date' => '2026-10-01']))
            ->assertOk()->assertSee('Continued from Sep 30, 2026')->assertSee('12:00 AM - 9:00 AM');
        $this->assertCount(0, ReservationAvailability::acceptedReservations($this->facility->id, $october, $october, 'Taft'));
        $r->update(['end_time' => '00:00']);
        $this->assertCount(0, ReservationAvailability::acceptedReservations($this->facility->id, $october, $october, 'Washington'));
    }

    public function test_admin_payment_and_accepted_displays_include_overnight_end_date(): void
    {
        $r = $this->booking(['status' => 'pending', 'end_time' => '00:00', 'total_payment' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('reservations.index'))->assertSee('120 minutes')->assertSee('₱100.00')->assertSee('Ends Sep 29, 2026');
        $this->post(route('reservations.accept', $r), ['total_payment' => '90.00', 'payment_confirmed' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($r->user)->get(route('reservations.index'))->assertSee('Total Paid: ₱90.00')->assertSee('Ends Sep 29, 2026');
        $this->assertContains('Ends Sep 29, 2026', $r->user->notifications()->first()->data['details']);
    }
}
