<?php

namespace Tests\Feature;

use App\Http\Controllers\PendingReservationController;
use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PendingReservationEditTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private User $admin;

    private Facility $facility;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay());
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->facility = Facility::create(['barangay' => 'Taft', 'slug' => 'court', 'name' => 'Covered Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100, 'reservation_access' => 'all_registered_users']);
        $this->reservation = Reservation::create([
            'user_id' => $this->resident->id, 'facility_id' => $this->facility->id, 'barangay' => 'Taft',
            'facility_slug' => 'court', 'facility_name' => 'Original Court', 'category' => 'Facility', 'location' => 'Original location',
            'reservation_date' => '2026-10-15', 'start_time' => '08:00:00', 'end_time' => '09:00:00',
            'purpose' => 'Original purpose', 'attendees' => 5, 'status' => 'pending', 'hourly_rate_snapshot' => 75,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [...['reservation_date' => '2026-10-15', 'start_time' => '14:00', 'end_time' => '16:00', 'purpose' => 'Updated purpose', 'attendees' => 12], ...$overrides];
    }

    private function update(array $overrides = [])
    {
        return $this->actingAs($this->resident)->patchJson(route('reservations.update-pending', $this->reservation), $this->payload($overrides));
    }

    public function test_owner_updates_only_editable_fields_preserving_payment_snapshots_identity_and_history(): void
    {
        Notification::fake();
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->reservation->update(['total_payment' => 123, 'change_history' => [['action' => 'legacy']]]);
        $payment = $this->reservation->payment()->create(['amount' => 7, 'payment_status' => 'pending']);
        $before = $this->reservation->fresh()->getAttributes();
        $paymentBefore = $payment->fresh()->getAttributes();
        $this->update(['id' => 999, 'user_id' => $otherAdmin->id, 'facility_id' => 999, 'barangay' => 'Washington', 'status' => 'accepted', 'hourly_rate_snapshot' => 999, 'total_payment' => 999, 'change_history' => [], 'cancelled_at' => now()->toDateTimeString(), 'facility_name' => 'Tampered'])->assertOk()->assertJsonPath('success', true);
        $current = $this->reservation->fresh();
        foreach ($before as $key => $value) {
            if (! in_array($key, ['reservation_date', 'start_time', 'end_time', 'purpose', 'attendees', 'change_history', 'updated_at'])) {
                $this->assertSame($value, $current->getAttributes()[$key], $key);
            }
        }
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('14:00', $current->start_time);
        $this->assertSame('16:00', $current->end_time);
        $this->assertSame('Updated purpose', $current->purpose);
        $this->assertSame(12, $current->attendees);
        $this->assertCount(2, $current->change_history);
        $entry = $current->change_history[1];
        $this->assertSame('pending_edit', $entry['action']);
        $this->assertSame($this->resident->id, $entry['actor_id']);
        $this->assertSame($this->resident->name, $entry['actor_name']);
        $this->assertSame(now()->toIso8601String(), $entry['at']);
        $this->assertSame('08:00:00', $entry['before']['start_time']);
        $this->assertSame('Original purpose', $entry['before']['purpose']);
        $this->assertSame($this->payload(), $entry['after']);
        Notification::assertSentToTimes($this->admin, ReservationActivity::class, 1);
        Notification::assertSentTo($this->admin, ReservationActivity::class, function ($notification) {
            $data = $notification->toDatabase($this->admin);

            return $data['event'] === 'pending_updated' && $data['status'] === 'pending'
                && $data['reservation_id'] === $this->reservation->id && $data['barangay'] === 'Taft'
                && $data['total_payment'] === null && $data['hourly_rate'] === null;
        });
        Notification::assertNotSentTo([$otherAdmin, $super, $this->resident], ReservationActivity::class);
    }

    public function test_identical_submission_normalizes_time_for_comparison_without_writing_or_notifying(): void
    {
        Notification::fake();
        $before = $this->reservation->fresh()->getAttributes();
        $this->update(['start_time' => '08:00', 'end_time' => '09:00', 'purpose' => 'Original purpose', 'attendees' => '5'])->assertOk()->assertJsonPath('message', 'No changes were made to this reservation.');
        $this->assertSame($before, $this->reservation->fresh()->getAttributes());
        Notification::assertNothingSent();
    }

    public function test_unauthorized_roles_and_other_owners_are_denied_before_invalid_input(): void
    {
        foreach ([User::factory()->create(), $this->admin, User::factory()->create(['role' => 'super_admin'])] as $actor) {
            $this->actingAs($actor)->patchJson(route('reservations.update-pending', $this->reservation), [])->assertForbidden();
        }
        $this->assertSame('08:00:00', $this->reservation->fresh()->start_time);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_guests_and_missing_reservations_follow_existing_response_conventions(): void
    {
        $this->patch(route('reservations.update-pending', $this->reservation), [])->assertRedirect(route('login'));
        $this->patchJson(route('reservations.update-pending', $this->reservation), [])->assertUnauthorized();
        $this->actingAs($this->resident)->patchJson(route('reservations.update-pending', 999999), [])->assertNotFound();
    }

    public function test_all_non_pending_statuses_are_rejected_before_field_validation(): void
    {
        foreach (['accepted', 'rejected', 'cancelled'] as $status) {
            $this->reservation->update(['status' => $status]);
            $this->actingAs($this->resident)->patchJson(route('reservations.update-pending', $this->reservation), [])->assertUnprocessable()->assertJsonValidationErrors('reservation')->assertJsonMissingValidationErrors('reservation_date');
            $this->assertSame($status, $this->reservation->fresh()->status);
        }
    }

    public function test_existing_verification_and_account_status_middleware_protects_pending_edits(): void
    {
        $this->resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->resident)->patch(route('reservations.update-pending', $this->reservation), $this->payload())->assertRedirect(route('verification.notice'));
        $this->resident->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($this->resident)->patch(route('reservations.update-pending', $this->reservation), $this->payload())->assertRedirect(route('login'));
        $this->assertSame('08:00:00', $this->reservation->fresh()->start_time);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_html_submission_redirects_to_existing_reservation_page(): void
    {
        Notification::fake();
        $this->actingAs($this->resident)->patch(route('reservations.update-pending', $this->reservation), $this->payload())
            ->assertRedirect(route('reservations.index'))->assertSessionHas('reservation_status', 'Reservation request updated successfully.');
    }

    public function test_creation_validation_rules_are_retained_without_side_effects(): void
    {
        Notification::fake();
        $before = $this->reservation->fresh()->getAttributes();
        foreach ([['reservation_date', 'not a date'], ['reservation_date', '2026-10-08'], ['start_time', '2pm'], ['end_time', '14:00'], ['purpose', str_repeat('x', 501)], ['purpose', ''], ['attendees', 0], ['attendees', 51], ['attendees', 1.5]] as [$field, $value]) {
            $this->update([$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, $this->reservation->fresh()->getAttributes());
        }
        Notification::assertNothingSent();
    }

    public function test_overnight_and_creation_supported_date_format_are_accepted_without_repricing(): void
    {
        Notification::fake();
        $this->update(['reservation_date' => 'October 15, 2026', 'start_time' => '22:00', 'end_time' => '02:00'])->assertOk();
        $current = $this->reservation->fresh();
        $this->assertSame(240, $current->period()->durationMinutes());
        $this->assertSame('2026-10-16', $current->period()->end->toDateString());
        $this->assertSame('75.00', $current->hourly_rate_snapshot);
        $this->assertNull($current->total_payment);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_existing_accepted_resident_overlap_does_not_block_pending_edit(): void
    {
        Notification::fake();
        $other = $this->reservation->replicate();
        $other->fill(['user_id' => User::factory()->create()->id, 'status' => 'accepted', 'start_time' => '14:00', 'end_time' => '16:00'])->save();
        $this->update()->assertOk();
        $this->assertSame('accepted', $other->fresh()->status);
    }

    public function test_official_use_overlap_including_previous_night_blocks_but_adjacent_time_is_allowed(): void
    {
        Notification::fake();
        OfficialUse::create(['barangay' => 'Taft', 'facility_id' => $this->facility->id, 'date' => '2026-10-14', 'start_time' => '22:00', 'end_time' => '15:00', 'purpose' => 'Assembly', 'status' => 'active', 'created_by' => $this->admin->id]);
        $this->update()->assertUnprocessable();
        $this->assertSame('08:00:00', $this->reservation->fresh()->start_time);
        Notification::assertNothingSent();
        $this->update(['start_time' => '15:00'])->assertOk();
    }

    public function test_current_resource_availability_access_and_capacity_are_enforced(): void
    {
        $this->facility->update(['status' => 'Unavailable']);
        $this->update()->assertUnprocessable()->assertJsonValidationErrors('reservation');
        $this->facility->update(['status' => 'Available', 'reservation_access' => 'residents_only']);
        $this->update()->assertForbidden();
        $this->facility->update(['reservation_access' => 'all_registered_users', 'capacity' => 3]);
        $this->update()->assertUnprocessable()->assertJsonValidationErrors('attendees');
        $this->assertSame('08:00:00', $this->reservation->fresh()->start_time);
    }

    public function test_legacy_resource_is_resolved_by_barangay_and_slug_without_repairing_snapshots(): void
    {
        Notification::fake();
        $this->reservation->update(['facility_id' => null]);
        $this->update()->assertOk();
        $this->assertNull($this->reservation->fresh()->facility_id);
        Notification::assertSentTo($this->admin, ReservationActivity::class);
        $this->reservation->update(['barangay' => 'Missing barangay']);
        $this->update()->assertUnprocessable()->assertJsonValidationErrors('reservation');
    }

    public function test_notifications_follow_current_resource_owner_without_rewriting_reservation_snapshot(): void
    {
        Notification::fake();
        $newAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->facility->update(['barangay' => 'Washington']);
        $this->update()->assertOk();
        $this->assertSame('Taft', $this->reservation->fresh()->barangay);
        Notification::assertNotSentTo($this->admin, ReservationActivity::class);
        Notification::assertSentTo($newAdmin, ReservationActivity::class, fn ($n) => $n->toDatabase($newAdmin)['barangay'] === 'Washington');
    }

    public function test_fresh_transaction_state_prevents_stale_edit_after_admin_accepts_or_rejects(): void
    {
        Notification::fake();
        foreach (['accept', 'reject'] as $action) {
            $this->reservation->refresh()->update(['status' => 'pending']);
            $stale = $this->reservation->fresh();
            $this->actingAs($this->admin)->postJson(route('reservations.'.$action, $this->reservation), ['total_payment' => 123, 'payment_confirmed' => 1, 'rejection_confirmed' => 1])->assertOk();
            $before = $this->reservation->fresh()->getAttributes();
            $this->actingAs($this->resident);
            $request = Request::create('/reservations/'.$stale->id.'/pending', 'PATCH', $this->payload());
            $request->setUserResolver(fn () => $this->resident);
            try {
                app(PendingReservationController::class)($request, $stale);
                $this->fail('A stale Pending instance must not overwrite an admin decision.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reservation', $exception->errors());
            }
            $this->assertSame($before, $this->reservation->fresh()->getAttributes());
        }
    }

    public function test_admin_reviews_updated_request_without_changing_acceptance_workflow(): void
    {
        Notification::fake();
        $this->update()->assertOk();
        $this->actingAs($this->admin)->postJson(route('reservations.accept', $this->reservation), ['total_payment' => 123, 'payment_confirmed' => 1])->assertOk();
        $current = $this->reservation->fresh();
        $this->assertSame('accepted', $current->status);
        $this->assertSame('14:00', $current->start_time);
        $this->assertSame('Updated purpose', $current->purpose);
        $this->assertSame('123.00', $current->payment->amount);
        $this->assertCount(1, $current->change_history);
    }

    public function test_owner_and_resource_binding_are_rechecked_inside_transaction(): void
    {
        $stale = $this->reservation->fresh();
        $this->actingAs($this->resident);
        $request = Request::create('/reservations/'.$stale->id.'/pending', 'PATCH', $this->payload());
        $request->setUserResolver(fn () => $this->resident);
        $this->reservation->update(['user_id' => User::factory()->create()->id]);
        try {
            app(PendingReservationController::class)($request, $stale);
            $this->fail('Fresh ownership must be checked.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This action is unauthorized.', $exception->getMessage());
        }
        $this->reservation->update(['user_id' => $this->resident->id, 'facility_id' => null]);
        try {
            app(PendingReservationController::class)($request, $stale);
            $this->fail('A stale resource binding must not be used.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reservation', $exception->errors());
        }
        $this->assertSame('08:00:00', $this->reservation->fresh()->start_time);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_database_notification_failure_rolls_back_update_and_history_and_queues_no_mail(): void
    {
        Bus::fake();
        Event::listen(NotificationSent::class, function ($event) {
            if ($event->channel === 'database') {
                throw new \RuntimeException('Simulated database notification failure');
            }
        });
        $before = $this->reservation->fresh()->getAttributes();
        $this->update()->assertStatus(500);
        $this->assertSame($before, $this->reservation->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 0);
        Bus::assertNothingDispatched();
    }

    public function test_resident_page_actions_details_and_refresh_fixtures_respect_status_ownership_and_payments(): void
    {
        Notification::fake();
        $this->reservation->payment()->create(['amount' => 999, 'payment_status' => 'paid']);
        foreach (['accepted', 'rejected', 'cancelled'] as $status) {
            $row = $this->reservation->replicate();
            $row->fill(['status' => $status, 'purpose' => ucfirst($status).' purpose', 'total_payment' => 150])->save();
            if ($status === 'accepted') {
                $row->payment()->create(['amount' => 7, 'payment_status' => 'refunded']);
            }
        }
        $missing = $this->reservation->replicate();
        $missing->fill(['facility_id' => null, 'facility_slug' => 'missing-resource', 'facility_name' => 'Legacy resource', 'hourly_rate_snapshot' => null])->save();
        $other = $this->reservation->replicate();
        $other->fill(['user_id' => User::factory()->create()->id, 'purpose' => 'Private other resident purpose'])->save();
        $response = $this->actingAs($this->resident)->get(route('reservations.index', ['search' => '', 'status' => 'all', 'sort' => 'date']))->assertOk()
            ->assertSee('Track your facility and equipment reservations.')
            ->assertSee('data-modal-open="resident-edit-1"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('Total Payment: ₱150.00')->assertSee('Refunded')
            ->assertDontSee('Total Paid:')->assertDontSee('₱999.00')->assertDontSee('₱7.00')
            ->assertDontSee('Private other resident purpose')
            ->assertDontSee('href="'.route('facilities.show', 'missing-resource').'"', false);
        foreach ([2, 3, 4, 5, 6] as $id) {
            $response->assertDontSee('data-modal-open="resident-edit-'.$id.'"', false);
        }
        $this->writeFixture('resident-before', $response->getContent());
        $this->update()->assertOk();
        $updated = $this->get(route('reservations.index'))->assertOk()->assertSee('2:00 PM - 4:00 PM')->assertSee('Updated purpose');
        $this->writeFixture('resident-after', $updated->getContent());
        $this->actingAs($this->admin)->get(route('reservations.index'))->assertOk()->assertSee('Pending request updated')->assertSee('Original purpose')->assertSee('Updated purpose')->assertDontSee('data-pending-reservation-action');
    }

    private function writeFixture(string $name, string $html): void
    {
        if ($directory = getenv('PENDING_EDIT_RENDER_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
