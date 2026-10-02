<?php

namespace Tests\Feature;

use App\Http\Controllers\OfficialUseController;
use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationAvailability;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfficialUseEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Facility $court;

    private Facility $chairs;

    private Facility $foreignCourt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->court = $this->resource('Taft', 'Court');
        $this->chairs = $this->resource('Taft', 'Chairs', 'Equipment');
        $this->foreignCourt = $this->resource('Washington', 'Foreign Court');
        $this->actingAs($this->admin);
    }

    private function resource(string $barangay, string $name, string $category = 'Facility'): Facility
    {
        return Facility::create(['barangay' => $barangay, 'slug' => strtolower(str_replace(' ', '-', $name)), 'name' => $name, 'category' => $category, 'description' => $name, 'capacity' => 50, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => 100]);
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['facility_id' => $this->court->id, 'date' => '2026-10-10', 'start_time' => '13:00', 'end_time' => '17:00', 'purpose' => 'Barangay Assembly'], $overrides);
    }

    private function officialUse(array $overrides = []): OfficialUse
    {
        $response = $this->postJson(route('official-uses.store'), $this->data($overrides))->assertCreated();

        return OfficialUse::findOrFail($response->json('id'));
    }

    private function edit(OfficialUse $use, array $overrides = [])
    {
        return $this->patchJson(route('official-uses.update', $use), $this->data($overrides));
    }

    private function reservation(string $status = 'accepted', array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'user_id' => User::factory()->create(['barangay' => 'Taft'])->id, 'barangay' => 'Taft', 'facility_id' => $this->court->id,
            'facility_slug' => $this->court->slug, 'facility_name' => 'Court', 'category' => 'Facility', 'location' => 'Taft',
            'reservation_date' => '2026-10-10', 'start_time' => '14:00', 'end_time' => '16:00', 'purpose' => 'Resident Workshop',
            'attendees' => 10, 'status' => $status, 'hourly_rate_snapshot' => 100, 'total_payment' => $status === 'accepted' ? 500 : null,
        ], $overrides));
    }

    public function test_six_columns_and_active_conflict_cancelled_action_matrix_with_same_page_dialogs(): void
    {
        $active = $this->officialUse(['date' => '2026-10-09']);
        $this->reservation();
        $conflict = $this->officialUse();
        $cancelled = $active->replicate();
        $cancelled->status = 'cancelled';
        $cancelled->date = '2026-10-11';
        $cancelled->save();
        $html = $this->get(route('official-uses.index'))->assertOk()->assertSee('<th scope="col">Actions</th>', false)
            ->assertSee('Edit Official Use')->assertSee('Save Changes')->assertSee('Overlapping Accepted resident reservations exist.')
            ->assertDontSee('Review Conflict')->assertDontSee('data-reservation-open="edit-', false)->getContent();
        $this->assertSame(6, substr_count($html, '<th scope="col"'));
        foreach ([$active, $conflict, $cancelled] as $use) {
            $this->assertStringContainsString('data-reservation-open="official-use-view-'.$use->id.'"', $html);
            $this->assertStringContainsString('id="official-use-view-'.$use->id.'"', $html);
            if ($use->status === 'cancelled') {
                $this->assertStringNotContainsString('data-reservation-open="official-use-edit-'.$use->id.'"', $html);
                $this->assertStringNotContainsString('id="official-use-edit-'.$use->id.'"', $html);
            } else {
                $this->assertStringContainsString('data-reservation-open="official-use-edit-'.$use->id.'"', $html);
                $this->assertStringContainsString('action="'.route('official-uses.update', $use).'"', $html);
            }
        }
        foreach (['Official Use ID', 'Resource', 'Date', 'Start Time', 'End Time', 'Purpose', 'Status'] as $field) {
            $this->assertStringContainsString('<dt>'.$field.'</dt>', $html);
        }
        $this->edit($cancelled)->assertUnprocessable();
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_all_editable_fields_update_the_existing_record_with_scoped_resource_and_owner(): void
    {
        $use = $this->officialUse();
        $this->edit($use, ['facility_id' => $this->chairs->id, 'date' => '2026-10-12', 'start_time' => '22:00', 'end_time' => '02:00', 'purpose' => 'Evening Program', 'barangay' => 'Washington', 'created_by' => 999, 'status' => 'cancelled'])
            ->assertOk()->assertJsonPath('id', $use->id)->assertJsonPath('message', 'Official Use updated successfully.');
        $use->refresh();
        $this->assertSame($this->chairs->id, $use->facility_id);
        $this->assertSame('Taft', $use->barangay);
        $this->assertSame($this->admin->id, $use->created_by);
        $this->assertSame('2026-10-12', $use->date);
        $this->assertSame('22:00', substr($use->start_time, 0, 5));
        $this->assertSame('02:00', substr($use->end_time, 0, 5));
        $this->assertSame('Evening Program', $use->purpose);
        $this->assertSame(240, $use->period()->durationMinutes());
        $this->assertSame('active', $use->status);
        $this->assertDatabaseCount('official_uses', 1);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_noop_purpose_and_still_overlapping_edits_keep_decisions_and_do_not_duplicate_notices(): void
    {
        $r = $this->reservation();
        $use = $this->officialUse();
        $conflict = $r->officialUseConflicts()->first();
        $this->actingAs($r->user)->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'reschedule'])->assertOk();
        $decision = $conflict->fresh()->toArray();
        $booking = $r->fresh()->toArray();
        $this->actingAs($this->admin);
        $this->edit($use)->assertOk(); // Excludes itself from Official Use overlap checks.
        $this->edit($use, ['purpose' => 'Updated Purpose'])->assertOk();
        $this->edit($use, ['start_time' => '12:00', 'end_time' => '18:00'])->assertOk();
        $this->assertSame($decision, $conflict->fresh()->toArray());
        $this->assertSame($booking, $r->fresh()->toArray());
        $this->assertSame('conflict', $use->fresh()->status);
        $this->assertSame(1, $r->user->notifications()->count());
        $this->assertDatabaseCount('official_use_conflicts', 1);
    }

    public function test_edit_validation_other_schedule_overlap_overnight_and_adjacency(): void
    {
        $use = $this->officialUse();
        $before = $use->toArray();
        foreach (array_keys($this->data()) as $field) {
            $data = $this->data();
            unset($data[$field]);
            $this->patchJson(route('official-uses.update', $use), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([['date' => '2026-10-01'], ['start_time' => '25:00'], ['end_time' => '13:00'], ['purpose' => str_repeat('a', 501)], ['facility_id' => 9999]] as $invalid) {
            $this->edit($use, $invalid)->assertUnprocessable();
        }
        $this->assertSame($before, $use->fresh()->toArray());
        $this->officialUse(['date' => '2026-10-11', 'start_time' => '23:00', 'end_time' => '02:00']);
        $this->edit($use, ['date' => '2026-10-12', 'start_time' => '01:00', 'end_time' => '03:00'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertSame($before, $use->fresh()->toArray());
        $this->edit($use, ['date' => '2026-10-12', 'start_time' => '02:00', 'end_time' => '03:00'])->assertOk();
        $this->assertDatabaseCount('official_uses', 2);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_edit_authorization_and_resource_tenant_scope_with_super_admin_transfer(): void
    {
        $use = $this->officialUse();
        foreach ([User::factory()->create(['barangay' => 'Taft']), User::factory()->create(['role' => 'admin', 'barangay' => 'Washington'])] as $actor) {
            $this->actingAs($actor);
            $this->edit($use)->assertForbidden();
        }
        $this->actingAs($this->admin);
        $this->edit($use, ['facility_id' => $this->foreignCourt->id])->assertForbidden();
        $this->assertSame($this->court->id, $use->fresh()->facility_id);
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super);
        $this->edit($use, ['facility_id' => $this->foreignCourt->id])->assertOk();
        $this->assertSame('Washington', $use->fresh()->barangay);
        $this->assertSame($this->admin->id, $use->fresh()->created_by);
        $this->actingAs($this->admin)->get(route('official-uses.index'))->assertViewHas('uses', fn ($rows) => $rows->total() === 0);
        $this->edit($use)->assertForbidden();
    }

    public function test_move_away_resolves_only_relationship_and_preserves_accepted_reservation_and_payment(): void
    {
        $r = $this->reservation();
        $use = $this->officialUse();
        $conflict = $r->officialUseConflicts()->first();
        $this->actingAs($r->user)->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'cancel'])->assertOk();
        $booking = $r->fresh()->toArray();
        $this->actingAs($this->admin);
        $this->edit($use, ['date' => '2026-10-12'])->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame($booking, $r->fresh()->toArray());
        $this->assertSame('resolved', $conflict->fresh()->resolution);
        $this->assertNotNull($conflict->fresh()->resolved_at);
        $this->assertSame('cancellation_requested', $conflict->fresh()->resolution_history[1]['resolution']);
        $this->assertSame(1, $r->user->notifications()->count());
        $this->get(route('reservations.index', ['conflict' => 'official_use']))->assertViewHas('reservations', fn ($rows) => $rows->total() === 0);
        $this->actingAs($r->user)->get(route('reservations.index'))->assertDontSee('Request Cancellation')->assertDontSee('Conflict: Official Use');
        $this->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'reschedule'])->assertUnprocessable();
    }

    public function test_all_new_pending_and_accepted_overlaps_are_processed_and_unrelated_bookings_are_untouched(): void
    {
        $old = $this->reservation();
        $use = $this->officialUse();
        $oldBooking = $old->fresh()->toArray();
        $accepted = collect(range(1, 3))->map(fn ($i) => $this->reservation('accepted', ['reservation_date' => '2026-10-12']));
        $pending = collect(range(1, 2))->map(fn ($i) => $this->reservation('pending', ['reservation_date' => '2026-10-12', 'facility_id' => $i === 2 ? null : $this->court->id]));
        $unrelated = collect([
            $this->reservation('accepted', ['reservation_date' => '2026-10-12', 'facility_id' => $this->chairs->id]),
            $this->reservation('pending', ['reservation_date' => '2026-10-12', 'facility_id' => null, 'barangay' => 'Washington']),
            $this->reservation('pending', ['reservation_date' => '2026-10-12', 'start_time' => '17:00', 'end_time' => '18:00']),
            $this->reservation('rejected', ['reservation_date' => '2026-10-12']),
        ]);
        $before = $unrelated->map(fn ($r) => $r->fresh()->toArray())->all();
        $this->edit($use, ['date' => '2026-10-12'])->assertOk()->assertJsonPath('status', 'conflict');
        $this->assertSame($oldBooking, $old->fresh()->toArray());
        $this->assertSame('resolved', $old->officialUseConflicts()->first()->resolution);
        foreach ($accepted as $r) {
            $this->assertSame('accepted', $r->fresh()->status);
            $this->assertSame('500.00', $r->fresh()->total_payment);
            $this->assertSame('awaiting_user_decision', $r->officialUseConflicts()->first()->resolution);
            $this->assertSame(1, $r->user->notifications()->count());
        }
        foreach ($pending as $r) {
            $this->assertSame('cancelled', $r->fresh()->status);
            $this->assertSame('Official Use', $r->fresh()->cancellation_reason);
            $this->assertCount(1, $r->fresh()->change_history);
            $this->assertSame(1, $r->user->notifications()->count());
            $this->assertSame('official_use_cancelled', $r->user->notifications()->first()->data['event']);
        }
        $this->assertSame($before, $unrelated->map(fn ($r) => $r->fresh()->toArray())->all());
        $this->assertDatabaseCount('official_use_conflicts', 4);
        $this->assertDatabaseCount('official_uses', 1);
        $this->assertSame(1, $old->user->notifications()->count());
    }

    public function test_moving_one_official_use_does_not_clear_another_active_conflict(): void
    {
        $r = $this->reservation();
        $first = $this->officialUse(['end_time' => '15:00']);
        $second = $this->officialUse(['start_time' => '15:00']);
        $this->edit($first, ['date' => '2026-10-12'])->assertOk();
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('conflict', $second->fresh()->status);
        $this->assertSame(1, $r->officialUseConflicts()->unresolved()->count());
        $this->get(route('reservations.index', ['conflict' => 'official_use']))->assertViewHas('reservations', fn ($rows) => $rows->total() === 1);
        $this->assertSame(2, $r->user->notifications()->count());
    }

    public function test_reintroduced_conflict_reuses_record_retains_history_and_notifies_once_for_new_occurrence(): void
    {
        $r = $this->reservation();
        $use = $this->officialUse();
        $conflict = $r->officialUseConflicts()->first();
        $this->actingAs($r->user)->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'reschedule'])->assertOk();
        $this->actingAs($this->admin);
        $this->edit($use, ['date' => '2026-10-12'])->assertOk();
        $this->edit($use)->assertOk();
        $this->edit($use)->assertOk();
        $conflict->refresh();
        $this->assertSame('awaiting_user_decision', $conflict->resolution);
        $this->assertNull($conflict->decided_at);
        $this->assertNull($conflict->resolved_at);
        $this->assertSame(['awaiting_user_decision', 'reschedule_requested', 'resolved'], array_column($conflict->resolution_history, 'resolution'));
        $this->assertNotNull($conflict->resolution_history[1]['decided_at']);
        $this->assertNotNull($conflict->resolution_history[2]['resolved_at']);
        $this->assertSame(2, $r->user->notifications()->count());
        $this->assertDatabaseCount('official_use_conflicts', 1);
        $this->assertSame('conflict', $use->fresh()->status);
    }

    public function test_calendar_moves_one_event_keeps_both_accepted_bookings_and_updates_availability(): void
    {
        $old = $this->reservation();
        $new = $this->reservation('accepted', ['reservation_date' => '2026-10-12', 'start_time' => '15:00', 'end_time' => '17:00']);
        $use = $this->officialUse();
        $this->edit($use, ['date' => '2026-10-12', 'start_time' => '14:00', 'end_time' => '18:00'])->assertOk();
        $events = ReservationAvailability::calendarEvents($this->court->id, Carbon::parse('2026-10-01'), true);
        $this->assertCount(1, $events->where('event_type', 'official_use'));
        $official = $events->firstWhere('event_type', 'official_use');
        $this->assertSame('2026-10-12T14:00:00', $official['start']);
        $this->assertSame('2026-10-12T18:00:00', $official['end']);
        $this->assertSame('conflict', $official['status']);
        $this->assertCount(2, $events->where('event_type', 'reservation'));
        $this->assertSame('available', ReservationAvailability::daySchedule($this->court->id, Carbon::parse('2026-10-10'))->firstWhere('start_time', '13:00')['status']);
        $this->assertSame('booked', ReservationAvailability::daySchedule($this->court->id, Carbon::parse('2026-10-12'))->firstWhere('start_time', '14:00')['status']);
        $requester = User::factory()->create(['barangay' => 'Taft']);
        $this->actingAs($requester);
        $data = ['reservation_date' => '2026-10-12', 'start_time' => '14:00', 'end_time' => '15:00', 'purpose' => 'Workshop', 'attendees' => 5];
        $this->post(route('reservations.store', $this->court->slug), $data)->assertSessionHasErrors('reservation');
        $this->post(route('reservations.store', $this->court->slug), array_merge($data, ['reservation_date' => '2026-10-10', 'start_time' => '13:00', 'end_time' => '14:00']))->assertSessionHasNoErrors();
        $this->assertSame('accepted', $old->fresh()->status);
        $this->assertSame('accepted', $new->fresh()->status);
    }

    public function test_changing_resource_resolves_old_conflict_despite_equal_times_and_detects_target_bookings(): void
    {
        $old = $this->reservation('accepted', ['facility_id' => null]);
        $target = $this->reservation('accepted', ['facility_id' => $this->chairs->id]);
        $pending = $this->reservation('pending', ['facility_id' => $this->chairs->id]);
        $use = $this->officialUse();
        $oldBooking = $old->fresh()->toArray();
        $this->edit($use, ['facility_id' => $this->chairs->id])->assertOk();
        $this->assertSame($oldBooking, $old->fresh()->toArray());
        $this->assertSame('resolved', $old->officialUseConflicts()->first()->resolution);
        $this->assertSame('awaiting_user_decision', $target->officialUseConflicts()->first()->resolution);
        $this->assertSame('accepted', $target->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('conflict', $use->fresh()->status);
        $this->assertCount(0, ReservationAvailability::calendarEvents($this->court->id, Carbon::parse('2026-10-01'))->where('event_type', 'official_use'));
        $this->assertCount(1, ReservationAvailability::calendarEvents($this->chairs->id, Carbon::parse('2026-10-01'))->where('event_type', 'official_use'));
    }

    public function test_notification_failure_rolls_back_schedule_cleanup_new_conflicts_and_pending_cancellations(): void
    {
        $old = $this->reservation();
        $use = $this->officialUse();
        $pending = $this->reservation('pending', ['reservation_date' => '2026-10-12']);
        $new = $this->reservation('accepted', ['reservation_date' => '2026-10-12']);
        $before = $use->toArray();
        $previous = $old->officialUseConflicts()->first()->toArray();
        Event::listen(NotificationSent::class, function () {
            throw new \RuntimeException('Notification failed');
        });
        $this->withoutExceptionHandling();
        try {
            $this->edit($use, ['date' => '2026-10-12']);
            $this->fail('Expected notification failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Notification failed', $error->getMessage());
        }
        $this->assertSame($before, $use->fresh()->toArray());
        $this->assertSame($previous, $old->officialUseConflicts()->first()->toArray());
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->change_history);
        $this->assertSame('accepted', $new->fresh()->status);
        $this->assertDatabaseCount('official_use_conflicts', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_new_conflict_is_still_managed_through_existing_accepted_edit_with_payment_preserved(): void
    {
        $r = $this->reservation('accepted', ['reservation_date' => '2026-10-12']);
        $use = $this->officialUse();
        $this->edit($use, ['date' => '2026-10-12'])->assertOk();
        $this->patchJson(route('reservations.edit-accepted', $r), ['action' => 'reschedule', 'reservation_date' => '2026-10-13', 'start_time' => '14:00', 'end_time' => '16:00'])->assertOk();
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame('500.00', $r->fresh()->total_payment);
        $this->assertSame('resolved', $r->officialUseConflicts()->first()->resolution);
        $this->assertSame('active', $use->fresh()->status);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_previously_cancelled_pending_request_is_not_reinstated_when_official_use_moves(): void
    {
        $r = $this->reservation('pending');
        $use = $this->officialUse();
        $before = $r->fresh()->toArray();
        $this->edit($use, ['date' => '2026-10-12'])->assertOk();
        $this->assertSame($before, $r->fresh()->toArray());
        $this->assertSame(1, $r->user->notifications()->count());
        $this->assertSame('active', $use->fresh()->status);
    }

    public function test_stale_resource_binding_is_rejected_without_acquiring_an_unordered_new_resource_lock(): void
    {
        $use = $this->officialUse();
        $this->edit($use, ['facility_id' => $this->chairs->id])->assertOk();
        $request = Request::create('/official-uses/'.$use->id, 'PATCH', $this->data());
        $request->setUserResolver(fn () => $this->admin);
        try {
            (new OfficialUseController)->update($request, $use);
            $this->fail('Expected stale resource binding to be rejected.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('changed while editing', $error->errors()['date'][0]);
        }
        $this->assertSame($this->chairs->id, $use->fresh()->facility_id);
    }
}
