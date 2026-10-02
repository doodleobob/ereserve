<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Support\OfficialUseScheduling;
use App\Support\ReservationAvailability;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfficialUseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Facility $court;

    private Facility $chairs;

    private Facility $otherCourt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->court = $this->resource('Taft', 'Court', 'Facility');
        $this->chairs = $this->resource('Taft', 'Chairs', 'Equipment');
        $this->otherCourt = $this->resource('Washington', 'Other Court', 'Facility');
        $this->actingAs($this->admin);
    }

    private function resource(string $barangay, string $name, string $category): Facility
    {
        return Facility::create(['barangay' => $barangay, 'slug' => strtolower(str_replace(' ', '-', $name)), 'name' => $name, 'category' => $category, 'description' => $name, 'capacity' => 50, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => 100]);
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['facility_id' => $this->court->id, 'date' => '2026-10-10', 'start_time' => '13:00', 'end_time' => '17:00', 'purpose' => 'Internal Assembly'], $overrides);
    }

    private function createUse(array $overrides = []): OfficialUse
    {
        $response = $this->actingAs($this->admin)->postJson(route('official-uses.store'), $this->data($overrides))->assertCreated()->assertJsonPath('success', true);

        return OfficialUse::findOrFail($response->json('id'));
    }

    private function reservation(string $status, array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'user_id' => User::factory()->create(['barangay' => 'Taft'])->id, 'barangay' => 'Taft', 'facility_id' => $this->court->id,
            'facility_slug' => $this->court->slug, 'facility_name' => 'Court', 'category' => 'Facility', 'location' => 'Taft',
            'reservation_date' => '2026-10-10', 'start_time' => '14:00', 'end_time' => '16:00', 'purpose' => 'Resident Workshop',
            'attendees' => 10, 'status' => $status, 'hourly_rate_snapshot' => 100, 'total_payment' => $status === 'accepted' ? 500 : null,
        ], $overrides));
    }

    private function calendarUrl(Facility $resource, string $date = '2026-10-10'): string
    {
        return route('calendar', ['barangay' => $resource->barangay, 'type' => strtolower($resource->category), 'facility' => $resource->slug, 'month' => substr($date, 0, 7), 'date' => $date]);
    }

    public function test_admin_access_and_resource_selection_are_scoped_with_super_admin_access(): void
    {
        $use = $this->createUse();
        $this->assertSame('Taft', $use->barangay);
        $this->assertSame($this->admin->id, $use->creator->id);
        $this->assertSame($this->court->id, $use->facility->id);
        $this->get(route('official-uses.index'))->assertOk()->assertSee('+ Add Official Use')->assertSee('<dialog id="add-official-use"', false)
            ->assertSee('data-reservation-action', false)->assertDontSee('Other Court')->assertSee('<th scope="col">Actions', false)
            ->assertDontSee('View Details')->assertDontSee('Review Conflict')->assertSee('reservation-table-actions');
        $this->postJson(route('official-uses.store'), $this->data(['facility_id' => $this->otherCourt->id]))->assertForbidden();
        $resident = User::factory()->create(['barangay' => 'Taft']);
        $this->actingAs($resident)->get(route('official-uses.index'))->assertForbidden();
        $this->postJson(route('official-uses.store'), $this->data())->assertForbidden();
        $this->get(route('reservations.index'))->assertDontSee('href="'.route('official-uses.index').'"', false);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($otherAdmin)->get(route('official-uses.index'))->assertViewHas('uses', fn ($rows) => $rows->total() === 0);
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->postJson(route('official-uses.store'), $this->data(['facility_id' => $this->otherCourt->id]))->assertCreated();
        $this->get(route('official-uses.index'))->assertSee('Other Court')->assertViewHas('uses', fn ($rows) => $rows->total() === 2);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_filters_search_sorting_pagination_and_sizes(): void
    {
        for ($i = 0; $i < 26; $i++) {
            OfficialUse::create([...$this->data(['date' => Carbon::parse('2026-10-10')->addDays($i)->toDateString(), 'purpose' => 'Program '.$i]), 'barangay' => 'Taft', 'status' => 'active']);
        }
        OfficialUse::create([...$this->data(['facility_id' => $this->chairs->id, 'purpose' => 'Equipment Assembly']), 'barangay' => 'Taft', 'status' => 'conflict']);
        OfficialUse::create([...$this->data(['facility_id' => $this->otherCourt->id, 'purpose' => 'Foreign Assembly']), 'barangay' => 'Washington', 'status' => 'active']);
        $url = route('official-uses.index');
        $this->get($url)->assertViewHas('uses', fn ($rows) => $rows->total() === 27 && $rows->count() === 10);
        $this->get($url.'?search=Equipment')->assertViewHas('uses', fn ($rows) => $rows->total() === 1);
        $this->get($url.'?search=Chairs')->assertViewHas('uses', fn ($rows) => $rows->total() === 1);
        $this->get($url.'?search=%231')->assertViewHas('uses', fn ($rows) => $rows->total() === 1 && $rows->first()->id === 1);
        $this->get($url.'?facility_id='.$this->chairs->id)->assertViewHas('uses', fn ($rows) => $rows->total() === 1);
        $this->get($url.'?status=conflict')->assertViewHas('uses', fn ($rows) => $rows->total() === 1);
        $this->get($url.'?status=active')->assertViewHas('uses', fn ($rows) => $rows->total() === 26);
        $this->get($url.'?from_date=2026-10-12&to_date=2026-10-13')->assertViewHas('uses', fn ($rows) => $rows->total() === 2);
        foreach ([10, 25, 50, 100] as $size) {
            $this->get($url.'?per_page='.$size)->assertViewHas('uses', fn ($rows) => $rows->perPage() === $size && $rows->count() === min(27, $size));
        }
        $this->get($url.'?per_page=10&page=3&sort=id&direction=asc')->assertViewHas('uses', fn ($rows) => $rows->currentPage() === 3 && $rows->first()->id === 21);
        $this->get($url.'?sort=resource&direction=asc')->assertViewHas('uses', fn ($rows) => $rows->first()->facility->name === 'Chairs');
        $this->get($url.'?sort=schedule&direction=desc')->assertViewHas('uses', fn ($rows) => $rows->first()->date === '2026-11-04');
        $this->get($url.'?search=Foreign')->assertViewHas('uses', fn ($rows) => $rows->isEmpty());
        foreach (['per_page=999', 'sort=invalid', 'direction=invalid', 'page=0', 'from_date=bad', 'status=bad', 'facility_id[]=1'] as $invalid) {
            $this->getJson($url.'?'.$invalid)->assertUnprocessable();
        }
    }

    public function test_required_fields_and_schedule_validation(): void
    {
        foreach (array_keys($this->data()) as $key) {
            $data = $this->data();
            unset($data[$key]);
            $this->postJson(route('official-uses.store'), $data)->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        foreach ([['date' => '2026-10-01'], ['date' => 'bad'], ['start_time' => '25:00'], ['end_time' => '13:00'], ['facility_id' => 9999], ['purpose' => str_repeat('a', 501)]] as $invalid) {
            $this->postJson(route('official-uses.store'), $this->data($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('official_uses', 0);
        $use = $this->createUse(['barangay' => 'Washington', 'status' => 'conflict', 'created_by' => 9999]);
        $this->assertSame('Taft', $use->barangay);
        $this->assertSame('active', $use->status);
        $this->assertSame($this->admin->id, $use->created_by);
    }

    public function test_overlap_rejection_adjacency_different_resources_and_overnight(): void
    {
        $this->createUse(['start_time' => '22:00', 'end_time' => '02:00']);
        foreach ([['start_time' => '23:00', 'end_time' => '01:00'], ['date' => '2026-10-11', 'start_time' => '01:00', 'end_time' => '03:00'], ['start_time' => '21:00', 'end_time' => '23:00']] as $overlap) {
            $this->postJson(route('official-uses.store'), $this->data($overlap))->assertUnprocessable();
        }
        $this->createUse(['date' => '2026-10-11', 'start_time' => '02:00', 'end_time' => '03:00']);
        $this->createUse(['start_time' => '21:00', 'end_time' => '22:00']);
        $this->createUse(['facility_id' => $this->chairs->id, 'start_time' => '22:00', 'end_time' => '02:00']);
        $this->assertDatabaseCount('official_uses', 4);
    }

    public function test_all_pending_conflicts_cancel_with_history_notifications_and_active_status(): void
    {
        $pending = collect(range(1, 5))->map(fn ($i) => $this->reservation('pending', $i === 5 ? ['facility_id' => null] : []));
        $unaffected = collect([
            $this->reservation('pending', ['start_time' => '17:00', 'end_time' => '18:00']),
            $this->reservation('pending', ['facility_id' => $this->chairs->id]),
            $this->reservation('pending', ['facility_id' => null, 'barangay' => 'Washington']),
            $this->reservation('pending', ['reservation_date' => '2026-10-11']),
        ]);
        $rejected = $this->reservation('rejected');
        $use = $this->createUse();
        $this->assertSame('active', $use->status);
        $this->assertDatabaseCount('official_use_conflicts', 0);
        foreach ($pending as $r) {
            $r->refresh();
            $this->assertSame('cancelled', $r->status);
            $this->assertSame('Official Use', $r->cancellation_reason);
            $this->assertNotNull($r->cancelled_at);
            $this->assertSame('pending', $r->change_history[0]['before']['status']);
            $this->assertNull($r->total_payment);
            $this->assertSame(1, $r->user->notifications()->count());
            $notice = $r->user->notifications()->first()->data;
            $this->assertSame('Reservation Cancelled Due to Official Use', $notice['title']);
            $this->assertStringContainsString('different available schedule', $notice['message']);
            $this->actingAs($r->user)->get(route('notifications.index'))->assertJsonPath('unread_count', 1);
            $this->get(route('reservations.index'))->assertSee('Cancellation Reason: Official Use')->assertDontSee('Request Reschedule');
        }
        foreach ($unaffected as $r) {
            $this->assertSame('pending', $r->fresh()->status);
            $this->assertSame(0, $r->user->notifications()->count());
        }
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertDatabaseCount('reservations', 10);
    }

    public function test_all_accepted_conflicts_keep_payment_and_status_and_notify_every_user_once(): void
    {
        $accepted = collect(range(1, 3))->map(fn ($i) => $this->reservation('accepted'));
        $pending = $this->reservation('pending');
        $cancelled = $this->reservation('cancelled');
        $use = $this->createUse();
        $this->assertSame('conflict', $use->status);
        $this->assertCount(3, $use->conflicts);
        foreach ($accepted as $r) {
            $this->assertSame('accepted', $r->fresh()->status);
            $this->assertSame('500.00', $r->fresh()->total_payment);
            $this->assertSame('awaiting_user_decision', $r->officialUseConflicts()->first()->resolution);
            $notice = $r->user->notifications()->first()->data;
            $this->assertSame('Official Use Schedule Conflict', $notice['title']);
            $this->assertStringContainsString('October 10, 2026', $notice['message']);
            $this->assertStringContainsString('2:00 PM', $notice['message']);
            $this->assertStringContainsString('Reschedule or Cancellation', $notice['message']);
        }
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame(0, $cancelled->user->notifications()->count());
        for ($i = 0; $i < 2; $i++) {
            $this->get(route('official-uses.index'))->assertOk();
            $this->get(route('reservations.index'))->assertOk();
            $this->get($this->calendarUrl($this->court))->assertOk();
            OfficialUseScheduling::recalculate($use);
        }
        $this->postJson(route('official-uses.store'), $this->data())->assertUnprocessable();
        foreach ($accepted as $r) {
            $this->assertSame(1, $r->user->notifications()->count());
        }
        $this->assertDatabaseCount('official_uses', 1);
    }

    public function test_user_preferences_record_without_modifying_bookings_and_are_owner_guarded(): void
    {
        $r = $this->reservation('accepted');
        $this->createUse();
        $conflict = $r->officialUseConflicts()->first();
        $url = route('official-use-conflicts.decision', $conflict);
        $other = User::factory()->create(['barangay' => 'Taft']);
        foreach ([$this->admin, $other] as $unauthorized) {
            $this->actingAs($unauthorized)->postJson($url, ['decision' => 'cancel'])->assertForbidden();
        }
        $this->actingAs($r->user)->get(route('reservations.index'))->assertSee('Request Reschedule')->assertSee('Request Cancellation')->assertSee('Awaiting User Decision');
        $this->postJson($url, ['decision' => 'bad'])->assertUnprocessable();
        $this->postJson($url, ['decision' => 'reschedule', 'reservation_date' => '2026-10-12', 'status' => 'cancelled'])->assertOk();
        $this->postJson($url, ['decision' => 'reschedule'])->assertOk();
        $this->assertSame('reschedule_requested', $conflict->fresh()->resolution);
        $this->assertNotNull($conflict->fresh()->decided_at);
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->post($url, ['decision' => 'cancel'])->assertRedirect(route('reservations.index', ['reservation' => $r->id]));
        $this->assertSame('cancellation_requested', $conflict->fresh()->resolution);
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame('2026-10-10', $r->fresh()->reservation_date);
        $this->assertSame('500.00', $r->fresh()->total_payment);
        $this->actingAs($this->admin)->get(route('notifications.index'))->assertJsonPath('unread_count', 2);
        $this->get(route('reservations.index', ['conflict' => 'official_use']))->assertSee('Cancellation Requested')->assertSee('data-reservation-open="edit-'.$r->id.'"', false);
    }

    public function test_conflict_filter_keeps_existing_admin_action_rules(): void
    {
        $r = $this->reservation('accepted');
        $other = $this->reservation('accepted', ['reservation_date' => '2026-10-11']);
        $this->createUse();
        $pending = $this->reservation('pending', ['reservation_date' => '2026-10-12']);
        $this->get(route('reservations.index', ['conflict' => 'official_use']))->assertSee('Accepted')->assertSee('Official Use')->assertSee('Awaiting User Decision')
            ->assertViewHas('reservations', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $r->id)
            ->assertSee('data-reservation-open="view-'.$r->id.'"', false)->assertSee('data-reservation-open="edit-'.$r->id.'"', false)
            ->assertDontSee('data-reservation-open="reject-'.$r->id.'"', false)->assertDontSee('Resolve Conflict')->assertDontSee('Review Conflict');
        $this->get(route('reservations.index', ['conflict' => 'none']))->assertViewHas('reservations', fn ($rows) => $rows->total() === 2);
        $this->get(route('reservations.index', ['conflict' => 'all']))->assertViewHas('reservations', fn ($rows) => $rows->total() === 3);
        $this->get(route('reservations.index', ['status' => 'pending']))->assertSee('data-reservation-open="reject-'.$pending->id.'"', false)->assertDontSee('data-reservation-open="edit-'.$pending->id.'"', false);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($otherAdmin)->get(route('reservations.index', ['conflict' => 'official_use']))->assertViewHas('reservations', fn ($rows) => $rows->total() === 0);
    }

    public function test_existing_edit_reschedules_and_cancels_until_final_conflict_is_resolved(): void
    {
        $first = $this->reservation('accepted');
        $last = $this->reservation('accepted');
        $use = $this->createUse();
        $this->patchJson(route('reservations.edit-accepted', $first), ['action' => 'reschedule', 'reservation_date' => '2026-10-10', 'start_time' => '16:00', 'end_time' => '18:00'])->assertUnprocessable();
        $this->assertSame('awaiting_user_decision', $first->officialUseConflicts()->first()->resolution);
        $this->patchJson(route('reservations.edit-accepted', $first), ['action' => 'reschedule', 'reservation_date' => '2026-10-11', 'start_time' => '22:00', 'end_time' => '02:00'])->assertOk();
        $this->assertSame('accepted', $first->fresh()->status);
        $this->assertSame('500.00', $first->fresh()->total_payment);
        $this->assertSame('resolved', $first->officialUseConflicts()->first()->resolution);
        $this->assertNotNull($first->officialUseConflicts()->first()->resolved_at);
        $this->assertSame('conflict', $use->fresh()->status);
        $this->get($this->calendarUrl($this->court))->assertViewHas('calendarEvents', fn ($events) => $events->where('event_type', 'official_use')->count() === 1 && $events->where('event_type', 'reservation')->count() === 3);
        $this->patchJson(route('reservations.edit-accepted', $last), ['action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'Official Use'])->assertOk();
        $this->assertSame('cancelled', $last->fresh()->status);
        $this->assertSame('500.00', $last->fresh()->total_payment);
        $this->assertSame('Official Use', $last->fresh()->cancellation_reason);
        $this->assertCount(1, $last->fresh()->change_history);
        $this->assertSame('resolved', $last->officialUseConflicts()->first()->resolution);
        $this->assertSame('active', $use->fresh()->status);
        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseCount('official_use_conflicts', 2);
        $this->get($this->calendarUrl($this->court))->assertViewHas('calendarEvents', fn ($events) => $events->where('event_type', 'official_use')->first()['status'] === 'active' && $events->where('event_type', 'reservation')->count() === 2);
        $this->actingAs($last->user)->postJson(route('official-use-conflicts.decision', $last->officialUseConflicts()->first()), ['decision' => 'cancel'])->assertUnprocessable();
    }

    public function test_one_reservation_can_resolve_multiple_official_use_conflicts(): void
    {
        $r = $this->reservation('accepted', ['start_time' => '08:00', 'end_time' => '18:00']);
        $first = $this->createUse(['start_time' => '09:00', 'end_time' => '10:00']);
        $second = $this->createUse(['start_time' => '11:00', 'end_time' => '12:00']);
        $this->assertCount(2, $r->officialUseConflicts);
        $this->patchJson(route('reservations.edit-accepted', $r), ['action' => 'reschedule', 'reservation_date' => '2026-10-10', 'start_time' => '18:00', 'end_time' => '19:00'])->assertOk();
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame(0, $r->officialUseConflicts()->unresolved()->count());
    }

    public function test_calendar_shows_both_types_and_protects_resident_and_other_admin_privacy(): void
    {
        $r = $this->reservation('accepted');
        $use = $this->createUse();
        $this->get($this->calendarUrl($this->court))->assertSee('Internal Assembly')->assertSee('Conflict')->assertSee('data-event-type="official_use"', false)
            ->assertViewHas('calendarEvents', function ($events) {
                $event = $events->firstWhere('event_type', 'official_use');

                return $events->count() === 2 && $event['start'] === '2026-10-10T13:00:00' && $event['end'] === '2026-10-10T17:00:00' && $event['status'] === 'conflict';
            });
        $resident = User::factory()->create(['barangay' => 'Washington']);
        foreach ([$resident, User::factory()->create(['role' => 'admin', 'barangay' => 'Washington'])] as $viewer) {
            $this->actingAs($viewer)->get($this->calendarUrl($this->court))->assertSee('Official Use')->assertSee('Resource unavailable')
                ->assertDontSee('Internal Assembly')->assertDontSee('Conflict')->assertDontSee($r->user->name)->assertDontSee($r->user->email)
                ->assertViewHas('calendarEvents', fn ($events) => ! array_key_exists('status', $events->firstWhere('event_type', 'official_use')));
            $this->get($this->calendarUrl($this->otherCourt))->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty());
            $this->get($this->calendarUrl($this->chairs))->assertViewHas('calendarEvents', fn ($events) => $events->isEmpty());
        }
        $this->actingAs($resident)->get($this->calendarUrl($this->court).'&start_time=14:00&end_time=16:00')->assertViewHas('selectedSlot', null)->assertDontSee('Submit Reservation');
        $this->court->update(['status' => 'Unavailable']);
        $this->get($this->calendarUrl($this->court))->assertSee('Official Use');
    }

    public function test_overnight_calendar_availability_and_new_reservation_blocking_with_adjacency(): void
    {
        $this->createUse(['date' => '2026-10-31', 'start_time' => '22:00', 'end_time' => '09:00']);
        $resident = User::factory()->create(['barangay' => 'Taft']);
        $nextDay = Carbon::parse('2026-11-01');
        $this->assertSame('booked', ReservationAvailability::daySchedule($this->court->id, $nextDay)->first()['status']);
        $this->assertSame('partial', ReservationAvailability::monthCalendar($this->court->id, $nextDay)->first()['status']);
        $this->actingAs($resident)->get($this->calendarUrl($this->court, '2026-11-01'))->assertSee('Continued from Oct 31, 2026')
            ->assertViewHas('calendarEvents', fn ($events) => $events->count() === 1 && $events->first()['start'] === '2026-11-01T00:00:00');
        $data = ['reservation_date' => '2026-11-01', 'start_time' => '08:00', 'end_time' => '10:00', 'purpose' => 'Workshop', 'attendees' => 5];
        $this->post(route('reservations.store', $this->court->slug), $data)->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 0);
        $this->post(route('reservations.store', $this->court->slug), array_merge($data, ['start_time' => '09:00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reservations', 1);
        $this->post(route('reservations.store', $this->chairs->slug), $data)->assertSessionHasNoErrors();
    }

    public function test_previous_day_overnight_pending_and_accepted_reservations_are_found(): void
    {
        $pending = $this->reservation('pending', ['reservation_date' => '2026-10-09', 'start_time' => '23:00', 'end_time' => '02:00']);
        $accepted = $this->reservation('accepted', ['reservation_date' => '2026-10-09', 'start_time' => '23:00', 'end_time' => '02:00']);
        $use = $this->createUse(['start_time' => '01:00', 'end_time' => '03:00']);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('conflict', $use->status);
        $this->assertCount(1, $use->conflicts);
    }

    public function test_conflicts_follow_managing_resource_for_cross_barangay_residents(): void
    {
        $resident = User::factory()->create(['barangay' => 'Washington']);
        $r = $this->reservation('accepted', ['user_id' => $resident->id]);
        $pending = $this->reservation('pending', ['user_id' => $resident->id]);
        $this->createUse();
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame(2, $resident->notifications()->count());
        $this->actingAs($resident)->postJson(route('official-use-conflicts.decision', $r->officialUseConflicts()->first()), ['decision' => 'reschedule'])->assertOk();
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_acceptance_cannot_introduce_an_official_use_overlap(): void
    {
        $this->createUse();
        $pending = $this->reservation('pending');
        $this->postJson(route('reservations.accept', $pending), ['total_payment' => 100, 'payment_confirmed' => 1])->assertUnprocessable();
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->total_payment);
        $this->assertSame(0, $pending->user->notifications()->count());
    }

    public function test_notification_failure_rolls_back_creation_and_all_reservation_changes(): void
    {
        $pending = $this->reservation('pending');
        $accepted = $this->reservation('accepted');
        Event::listen(NotificationSent::class, function () {
            throw new \RuntimeException('Notification write failed');
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson(route('official-uses.store'), $this->data());
            $this->fail('Expected notification failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Notification write failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('official_uses', 0);
        $this->assertDatabaseCount('official_use_conflicts', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->change_history);
        $this->assertSame('accepted', $accepted->fresh()->status);
    }

    public function test_facility_deletion_preserves_official_use_and_conflict_history(): void
    {
        $r = $this->reservation('accepted');
        $this->createUse();
        $this->delete(route('facilities.destroy', $this->court->slug))->assertSessionHasErrors('facility');
        $this->assertDatabaseHas('facilities', ['id' => $this->court->id]);
        $this->assertDatabaseCount('official_uses', 1);
        $this->assertDatabaseCount('official_use_conflicts', 1);
        $this->assertSame('500.00', $r->fresh()->total_payment);
    }

    public function test_legacy_schema_upgrade_preserves_original_rows_payments_and_conflict_history(): void
    {
        $r = $this->reservation('accepted');
        Schema::drop('official_use_conflicts');
        Schema::drop('official_uses');
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('total_payment'));
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->decimal('amount', 12, 2);
            $table->string('payment_status');
        });
        DB::table('payments')->insert(['reservation_id' => $r->id, 'amount' => 500, 'payment_status' => 'paid']);
        Schema::create('official_uses', function (Blueprint $table) {
            $table->id();
            foreach (['facility_id', 'reservation_id', 'schedule_id', 'initiated_by'] as $field) {
                $table->unsignedBigInteger($field)->nullable();
            }
            $table->string('reason');
            $table->date('reservation_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('state');
            $table->string('resident_decision')->nullable();
            foreach (['choice_submitted_at', 'resolved_at', 'cancelled_at'] as $field) {
                $table->timestamp($field)->nullable();
            }
            $table->timestamps();
        });
        $base = ['facility_id' => $this->court->id, 'initiated_by' => $this->admin->id, 'reservation_date' => '2026-10-10', 'start_time' => '13:00', 'end_time' => '17:00', 'reason' => 'Historical Program', 'created_at' => now(), 'updated_at' => now()];
        DB::table('official_uses')->insert([...$base, 'id' => 10, 'state' => 'pending_conflicts']);
        DB::table('official_uses')->insert([...$base, 'id' => 11, 'schedule_id' => 10, 'reservation_id' => $r->id, 'state' => 'awaiting_resident', 'resident_decision' => 'reschedule', 'choice_submitted_at' => now()]);
        DB::table('official_uses')->insert([...$base, 'id' => 12, 'reservation_id' => $r->id, 'reservation_date' => '2026-10-09', 'state' => 'rescheduled', 'resolved_at' => now()]);
        $original = DB::table('official_uses')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_02_000002_create_official_uses.php');
        $migration->up();
        $this->assertSame($original, DB::table('legacy_official_uses')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('official_uses', 2);
        $this->assertDatabaseCount('official_use_conflicts', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('500.00', $r->fresh()->total_payment);
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame('conflict', OfficialUse::findOrFail(10)->status);
        $this->assertSame('active', OfficialUse::findOrFail(12)->status);
        $this->assertDatabaseHas('official_use_conflicts', ['official_use_id' => 10, 'reservation_id' => $r->id, 'resolution' => 'reschedule_requested']);
        $this->assertDatabaseHas('official_use_conflicts', ['official_use_id' => 12, 'reservation_id' => $r->id, 'resolution' => 'resolved']);
        // Retry after an interrupted schema upgrade does not duplicate history or replay notices.
        $migration->up();
        $this->assertDatabaseCount('official_uses', 2);
        $this->assertDatabaseCount('official_use_conflicts', 2);
        $migration->down();
        $this->assertSame($original, DB::table('official_uses')->orderBy('id')->get()->toJson());
        Schema::drop('official_uses');
        Schema::drop('payments');
        $migration->up();
    }
}
