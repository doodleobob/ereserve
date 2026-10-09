<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FutureReservationStartTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private User $admin;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-09 23:00:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->facility = Facility::create(['barangay' => 'Taft', 'slug' => 'future-court', 'name' => 'Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
        $this->actingAs($this->resident);
    }

    private function payload(array $overrides = []): array
    {
        return [...['reservation_date' => '2026-10-09', 'start_time' => '23:15', 'end_time' => '00:30', 'purpose' => 'Community event', 'attendees' => 5], ...$overrides];
    }

    private function booking(string $status = 'pending'): Reservation
    {
        return Reservation::create([...$this->payload(['reservation_date' => '2026-10-10']), 'user_id' => $this->resident->id, 'facility_id' => $this->facility->id, 'facility_slug' => $this->facility->slug, 'facility_name' => $this->facility->name, 'category' => 'Facility', 'barangay' => 'Taft', 'location' => 'Taft', 'status' => $status, 'hourly_rate_snapshot' => 100, 'total_payment' => $status === 'accepted' ? 125 : null]);
    }

    public static function pastStarts(): array
    {
        return [
            'past date' => ['2026-10-08', '23:15', 'reservation_date'],
            '8 PM today' => ['2026-10-09', '20:00', 'start_time'],
            '9 PM today' => ['2026-10-09', '21:00', 'start_time'],
            '10:30 PM today' => ['2026-10-09', '22:30', 'start_time'],
            'equal to now' => ['2026-10-09', '23:00', 'start_time'],
        ];
    }

    #[DataProvider('pastStarts')]
    public function test_direct_creation_requests_reject_past_or_equal_starts(string $date, string $time, string $field): void
    {
        $response = $this->postJson(route('reservations.store', $this->facility->slug), $this->payload(['reservation_date' => $date, 'start_time' => $time]));
        $response->assertUnprocessable()->assertJsonValidationErrors($field);
        if ($field === 'start_time') {
            $response->assertJsonPath('errors.start_time.0', 'The selected reservation start time must be in the future.');
        }
        $this->assertDatabaseCount('reservations', 0);
        Notification::assertNothingSent();
    }

    public static function futureStarts(): array
    {
        return [
            'next minute today' => ['2026-10-09', '23:01', '23:59', 58],
            'minute-level overnight today' => ['2026-10-09', '23:17', '00:09', 52],
            'future date with early time' => ['2026-10-10', '00:00', '01:00', 60],
        ];
    }

    #[DataProvider('futureStarts')]
    public function test_future_and_overnight_starts_keep_existing_storage_and_duration(string $date, string $start, string $end, int $duration): void
    {
        $this->postJson(route('reservations.store', $this->facility->slug), $this->payload(['reservation_date' => $date, 'start_time' => $start, 'end_time' => $end]))->assertCreated();
        $reservation = Reservation::firstOrFail();
        $this->assertSame($date, $reservation->reservation_date);
        $this->assertSame($start, $reservation->start_time);
        $this->assertSame($end, $reservation->end_time);
        $this->assertSame($duration, $reservation->durationMinutes());
        $this->assertSame('Asia/Manila', $reservation->period()->start->timezoneName);
    }

    #[DataProvider('pastStarts')]
    public function test_pending_edits_cannot_move_a_reservation_into_the_past(string $date, string $time, string $field): void
    {
        $reservation = $this->booking();
        $before = $reservation->fresh()->getAttributes();
        $this->patchJson(route('reservations.update-pending', $reservation), $this->payload(['reservation_date' => $date, 'start_time' => $time]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        Notification::assertNothingSent();
    }

    #[DataProvider('pastStarts')]
    public function test_admin_rescheduling_cannot_move_an_accepted_reservation_into_the_past(string $date, string $time, string $field): void
    {
        $reservation = $this->booking('accepted');
        $before = $reservation->fresh()->getAttributes();
        $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $reservation), $this->payload(['action' => 'reschedule', 'reservation_date' => $date, 'start_time' => $time]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        Notification::assertNothingSent();
    }

    public function test_pending_and_accepted_rescheduling_keep_valid_overnight_times_and_payments(): void
    {
        foreach (['pending', 'accepted'] as $status) {
            $reservation = $this->booking($status);
            $payload = $this->payload(['start_time' => '23:17', 'end_time' => '00:09']);
            if ($status === 'pending') {
                $this->patchJson(route('reservations.update-pending', $reservation), $payload)->assertOk();
            } else {
                $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $reservation), ['action' => 'reschedule', ...$payload])->assertOk();
            }
            $current = $reservation->fresh();
            $this->assertSame(52, $current->durationMinutes());
            $this->assertSame('2026-10-10', $current->period()->end->toDateString());
            $this->assertSame($status === 'accepted' ? '125.00' : null, $current->total_payment);
            $reservation->delete();
        }
    }

    public function test_same_minute_with_elapsed_seconds_is_past(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 23:15:30', 'Asia/Manila'));
        $this->postJson(route('reservations.store', $this->facility->slug), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('start_time');
    }

    public function test_form_opened_before_a_slot_starts_is_rejected_after_time_passes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:55:00', 'Asia/Manila'));
        $this->get(route('dashboard', ['facility' => $this->facility->slug, 'date' => '2026-10-09', 'start_time' => '11:00', 'end_time' => '12:00']))->assertOk()->assertSee('data-future-reservation', false)->assertSee('name="start_time" value="11:00"', false);
        $this->travelTo(Carbon::parse('2026-10-09 11:10:00', 'Asia/Manila'));
        $this->postJson(route('reservations.store', $this->facility->slug), $this->payload(['start_time' => '11:00', 'end_time' => '12:00']))->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_html_requests_also_reject_past_starts(): void
    {
        $this->from(route('facilities.show', $this->facility->slug))->post(route('reservations.store', $this->facility->slug), $this->payload(['start_time' => '20:00']))->assertRedirect()->assertSessionHasErrors('start_time');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_manila_day_and_clock_are_used_even_if_application_timezone_is_overridden(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-10-09 16:00:00', 'UTC')); // Oct 10, midnight in Manila.
        $this->postJson(route('reservations.store', $this->facility->slug), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('reservation_date');
        $this->postJson(route('reservations.store', $this->facility->slug), $this->payload(['reservation_date' => '2026-10-10', 'start_time' => '00:00']))->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->postJson(route('reservations.store', $this->facility->slug), $this->payload(['reservation_date' => '2026-10-10', 'start_time' => '00:01', 'end_time' => '01:00']))->assertCreated();
    }

    public function test_historical_calendar_events_remain_viewable(): void
    {
        $reservation = $this->booking('accepted');
        $reservation->update(['reservation_date' => '2026-10-08', 'start_time' => '20:00', 'end_time' => '21:00']);
        $this->get(route('dashboard', ['facility' => $this->facility->slug, 'month' => '2026-10', 'date' => '2026-10-08']))->assertOk()->assertSee('8:00 PM - 9:00 PM')->assertSee('October 8, 2026')
            ->assertViewHas('calendarEvents', fn ($events) => $events->contains(fn ($event) => str_starts_with($event['start'], '2026-10-08')));
    }

    public function test_all_scheduling_forms_load_shared_validation_with_minute_inputs(): void
    {
        $this->booking();
        $this->booking('accepted');
        $pages = [
            'facility' => route('facilities.show', $this->facility->slug),
            'facility-modal' => route('facilities'),
            'calendar' => route('dashboard', ['facility' => $this->facility->slug, 'date' => '2026-10-10', 'start_time' => '08:00', 'end_time' => '09:00']),
            'pending' => route('reservations.index'),
        ];
        foreach ([...$pages, 'accepted' => route('reservations.index')] as $name => $url) {
            $this->actingAs($name === 'accepted' ? $this->admin : $this->resident);
            $html = $this->get($url)->assertOk()->assertSee('data-future-reservation', false)->getContent();
            $this->assertSame(1, substr_count($html, 'js/reservation-time-validation.js'));
            $this->assertStringNotContainsString('step="900"', $html);
            $this->assertStringNotContainsString('step="1800"', $html);
            if ($directory = getenv('FUTURE_START_RENDER_DIR')) {
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($directory.'/'.$name.'.html', $html);
            }
        }
    }
}
