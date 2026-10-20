<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Support\OfficialUseScheduling;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Console\ListenCommand;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Listener;
use Illuminate\Queue\ListenerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ReservationEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private User $admin;

    private User $otherAdmin;

    private Facility $facility;

    /** @var Email[] */
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['app.url' => 'https://ereserve.example.test']);
        $this->resident = User::factory()->create(['name' => 'Juan Resident', 'barangay' => 'Washington', 'email' => 'juan@example.test']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->facility = Facility::create(['barangay' => 'Taft', 'slug' => 'court', 'name' => 'Covered Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100, 'reservation_access' => 'all_registered_users']);
        Event::listen(MessageSent::class, function ($event) {
            $this->messages[] = $event->sent->getOriginalMessage();
        });
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::create([
            'user_id' => $this->resident->id, 'facility_id' => $this->facility->id, 'barangay' => 'Taft',
            'facility_slug' => 'court', 'facility_name' => 'Covered Court', 'category' => 'Facility', 'location' => 'Taft',
            'reservation_date' => today()->addDays(2)->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00',
            'purpose' => 'Workshop', 'attendees' => 5, 'status' => 'accepted', 'hourly_rate_snapshot' => 100, 'total_payment' => '1250.25',
            ...$overrides,
        ]);
    }

    private function submit(): Reservation
    {
        $this->actingAs($this->resident)->postJson(route('reservations.store', 'court'), [
            'reservation_date' => today()->addDays(2)->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00',
            'purpose' => 'Workshop', 'attendees' => 5,
        ])->assertCreated();

        return Reservation::latest('id')->firstOrFail();
    }

    private function accept(Reservation $reservation): void
    {
        $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), [
            'total_payment' => '1250.25', 'payment_confirmed' => 1,
        ])->assertOk();
    }

    private function cancel(Reservation $reservation): void
    {
        $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $reservation), [
            'action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'Official Use', 'cancellation_notes' => 'Barangay assembly.',
        ])->assertOk();
    }

    private function reschedule(Reservation $reservation, ?string $date = null): void
    {
        $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $reservation), [
            'action' => 'reschedule', 'reservation_date' => $date ?? today()->addDays(3)->toDateString(), 'start_time' => '22:00', 'end_time' => '02:00', 'end_next_day' => 1,
        ])->assertOk();
    }

    private function officialUse(Reservation $reservation, array $overrides = []): OfficialUse
    {
        $response = $this->actingAs($this->admin)->postJson(route('official-uses.store'), [
            'facility_id' => $this->facility->id, 'date' => $reservation->reservation_date,
            'start_time' => '12:00', 'end_time' => '16:00', 'purpose' => 'Barangay assembly', ...$overrides,
        ])->assertCreated();

        return OfficialUse::findOrFail($response->json('id'));
    }

    private function residentMessage(string $subject): Email
    {
        $messages = array_values(array_filter($this->messages, fn (Email $message) => $message->getSubject() === $subject && $message->getTo()[0]->getAddress() === $this->resident->email));
        $this->assertCount(1, $messages);

        return $messages[0];
    }

    public function test_submission_preserves_admin_bell_and_adds_resident_bell_and_pending_email_with_tenant_isolation(): void
    {
        $otherResident = User::factory()->create();
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $reservation = $this->submit();
        $this->assertSame('pending', $reservation->status);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'submission_confirmed')->count());
        $this->assertSame(1, $this->admin->notifications()->where('data->event', 'submitted')->count());
        $this->assertSame(0, $this->otherAdmin->notifications()->count());
        $this->assertCount(0, $this->messages);
        $this->assertDatabaseCount('jobs', 2);
        $this->drainMailQueue();
        $this->assertCount(2, $this->messages);
        $html = $this->residentMessage('eReserve - Reservation Submitted')->getHtmlBody();
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('awaiting review', $html);
        $this->assertStringNotContainsString('Accepted', $html);
        $this->assertStringNotContainsString('booked', $html);
        $this->assertStringContainsString('Hello Juan Resident', $html);
        $this->assertStringContainsString('https://ereserve.example.test/reservations?reservation='.$reservation->id, $html);
        $addresses = array_map(fn (Email $message) => $message->getTo()[0]->getAddress(), $this->messages);
        $this->assertContains($this->admin->email, $addresses);
        foreach ([$this->otherAdmin, $otherResident, $superAdmin] as $unrelated) {
            $this->assertNotContains($unrelated->email, $addresses);
        }
        $this->actingAs($this->resident)->getJson(route('notifications.index'))->assertJsonPath('notifications.data.0.data.event', 'submission_confirmed');
    }

    public function test_acceptance_sends_both_channels_once_and_uses_reservation_total(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'pending']);
        $this->accept($reservation);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'accepted')->count());
        $this->assertCount(0, $this->messages);
        $this->assertDatabaseCount('jobs', 1);
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Reservation Confirmed')->getHtmlBody();
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringContainsString('₱1,250.25', $html);
        $this->assertStringNotContainsString('₱7.00', $html);
        $this->assertStringNotContainsString('Amount', $html);
        $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), ['total_payment' => '1250.25', 'payment_confirmed' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->messages);
    }

    public function test_rejection_sends_email_and_in_app_without_inventing_a_reason(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->actingAs($this->admin)->postJson(route('reservations.reject', $reservation), ['rejection_confirmed' => 1])->assertOk();
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Reservation Update')->getHtmlBody();
        $this->assertStringContainsString('has been rejected', $html);
        $this->assertStringContainsString('Rejected', $html);
        $this->assertStringNotContainsString('Reason:', $html);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'rejected')->count());
        $this->postJson(route('reservations.reject', $reservation), ['rejection_confirmed' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->messages);
    }

    public function test_cancellation_marks_refund_pending_and_email_never_claims_a_refund(): void
    {
        $reservation = $this->reservation();
        $payment = $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'paid']);
        $this->cancel($reservation);
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Reservation Cancelled')->getHtmlBody();
        $this->assertStringContainsString('Cancelled', $html);
        $this->assertStringContainsString('Official Use', $html);
        $this->assertStringContainsString('Barangay assembly.', $html);
        $this->assertStringNotContainsString('refund', strtolower($html));
        $this->assertSame('refund_pending', $payment->fresh()->payment_status);
        $this->assertNull($payment->fresh()->refunded_at);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'cancelled')->count());
    }

    public function test_rescheduling_sends_new_and_previous_overnight_schedule_without_creating_payment(): void
    {
        $reservation = $this->reservation();
        $payment = $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'paid']);
        $before = $payment->fresh()->toArray();
        $this->reschedule($reservation);
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Reservation Rescheduled')->getHtmlBody();
        $this->assertStringContainsString('New Date', $html);
        $this->assertStringContainsString(today()->addDays(3)->format('F j, Y'), $html);
        $this->assertStringContainsString(today()->addDays(4)->format('F j, Y').' 2:00 AM', $html);
        $this->assertStringContainsString('10:00 PM', $html);
        $this->assertStringContainsString('Previous Schedule', $html);
        $this->assertStringContainsString('1:00 PM', $html);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'rescheduled')->count());
        $this->assertSame($before, $payment->fresh()->toArray());
        $this->assertDatabaseCount('payments', 1);
        $this->reschedule($reservation);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->messages);
    }

    public function test_official_use_conflict_preserves_accepted_status_and_does_not_renotify_on_reads_recalculation_or_same_edit(): void
    {
        $reservation = $this->reservation();
        $use = $this->officialUse($reservation);
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Official Use Schedule Conflict')->getHtmlBody();
        $this->assertStringContainsString('Reschedule or Cancellation', $html);
        $this->assertStringNotContainsString('has been cancelled', $html);
        $this->assertSame('accepted', $reservation->fresh()->status);
        for ($i = 0; $i < 2; $i++) {
            $this->get(route('official-uses.index'))->assertOk();
            $this->getJson(route('calendar'))->assertOk();
            $this->getJson(route('notifications.index'))->assertOk();
            $this->get(route('payments.index'))->assertOk();
            OfficialUseScheduling::recalculate($use);
        }
        $this->patchJson(route('official-uses.update', $use), [
            'facility_id' => $this->facility->id, 'date' => $use->date, 'start_time' => '12:00', 'end_time' => '16:00', 'purpose' => $use->purpose,
        ])->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->messages);
        $this->assertSame(1, $this->resident->notifications()->count());
    }

    public function test_official_use_pending_cancellation_sends_email_without_payment_or_refund(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->officialUse($reservation);
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Reservation Cancelled Due to Official Use')->getHtmlBody();
        $this->assertStringContainsString('Your pending reservation', $html);
        $this->assertStringContainsString('official barangay use', $html);
        $this->assertStringContainsString('different available schedule', $html);
        $this->assertStringNotContainsString('refund', strtolower($html));
        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'official_use_cancelled')->count());
    }

    public function test_rescheduled_official_use_notifies_each_new_conflict_occurrence_once(): void
    {
        $reservation = $this->reservation();
        $use = $this->officialUse($reservation, ['date' => today()->addDays(4)->toDateString()]);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(0, $this->messages);
        $data = ['facility_id' => $this->facility->id, 'date' => $reservation->reservation_date, 'start_time' => '12:00', 'end_time' => '16:00', 'purpose' => $use->purpose];
        $this->patchJson(route('official-uses.update', $use), $data)->assertOk();
        $this->drainMailQueue();
        $this->residentMessage('eReserve - Official Use Schedule Conflict');
        $this->patchJson(route('official-uses.update', $use), $data)->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->messages);
        $this->patchJson(route('official-uses.update', $use), [...$data, 'date' => today()->addDays(4)->toDateString()])->assertOk();
        $this->patchJson(route('official-uses.update', $use), $data)->assertOk();
        $this->drainMailQueue();
        $this->assertCount(2, $this->messages);
        $this->assertSame('accepted', $reservation->fresh()->status);
    }

    public function test_official_use_reschedule_resolution_sends_only_one_final_email(): void
    {
        $reservation = $this->reservation();
        $use = $this->officialUse($reservation);
        $conflict = $use->conflicts()->firstOrFail();
        $this->actingAs($this->resident)->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'reschedule'])->assertOk();
        $this->assertDatabaseCount('jobs', 1);
        $this->drainMailQueue();
        $this->assertCount(1, $this->messages);
        $this->reschedule($reservation);
        $this->drainMailQueue();
        $this->residentMessage('eReserve - Reservation Rescheduled');
        $this->assertCount(2, $this->messages);
        $this->assertSame('resolved', $conflict->fresh()->resolution);
    }

    public function test_official_use_cancel_resolution_sends_only_one_final_email(): void
    {
        $reservation = $this->reservation();
        $use = $this->officialUse($reservation);
        $conflict = $use->conflicts()->firstOrFail();
        $this->actingAs($this->resident)->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'cancel'])->assertOk();
        $this->assertDatabaseCount('jobs', 1);
        $this->cancel($reservation);
        $this->drainMailQueue();
        $this->residentMessage('eReserve - Reservation Cancelled');
        $this->assertCount(2, $this->messages);
        $this->assertSame('resolved', $conflict->fresh()->resolution);
    }

    public function test_paid_to_paid_is_silent_and_refund_notifies_once_using_reservation_total(): void
    {
        $reservation = $this->reservation(['status' => 'cancelled']);
        $payment = $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'paid']);
        $this->actingAs($this->admin)->patchJson(route('payments.update', $payment), ['payment_status' => 'paid'])->assertOk();
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refund_pending'])->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(0, $this->messages);
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded', 'receipt_confirmed' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(0, $this->messages);
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded', 'receipt_confirmed' => 1])->assertOk();
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Payment Refunded')->getHtmlBody();
        $this->assertStringContainsString('₱1,250.25', $html);
        $this->assertStringNotContainsString('₱7.00', $html);
        $this->assertStringNotContainsString('Amount', $html);
        $this->assertStringContainsString('Payment Status', $html);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'payment_refunded')->count());
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded'])->assertOk();
        $this->get(route('payments.index'))->assertOk();
        $this->get(route('payments.index'))->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->messages);
    }

    public function test_total_correction_notifies_only_after_a_meaningful_change(): void
    {
        $reservation = $this->reservation();
        $this->actingAs($this->admin)->patchJson(route('reservations.payment', $reservation), ['total_payment' => '1300'])->assertOk();
        $this->drainMailQueue();
        $html = $this->residentMessage('eReserve - Total Paid Updated')->getHtmlBody();
        $this->assertStringContainsString('₱1,300.00', $html);
        $this->assertStringContainsString('₱1,250.25', $html);
        $this->patchJson(route('reservations.payment', $reservation), ['total_payment' => '1300.00'])->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->messages);
    }

    public function test_mail_waits_for_commit_and_rollback_discards_both_channels(): void
    {
        $reservation = $this->reservation();
        DB::transaction(function () use ($reservation) {
            $this->resident->notify(new ReservationActivity($reservation, 'accepted'));
            $this->assertCount(0, $this->messages);
            $this->assertDatabaseCount('notifications', 1);
            $this->assertDatabaseCount('jobs', 0);
        });
        $this->assertCount(0, $this->messages);
        $this->assertDatabaseCount('jobs', 1);
        $this->drainMailQueue();
        $this->assertCount(1, $this->messages);
        $this->messages = [];
        try {
            DB::transaction(function () use ($reservation) {
                $reservation->update(['status' => 'cancelled', 'cancellation_reason' => 'Official Use']);
                $this->resident->notify(new ReservationActivity($reservation, 'cancelled'));
                $this->assertCount(0, $this->messages);
                throw new \RuntimeException('Roll back action');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Roll back action', $exception->getMessage());
        }
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertCount(0, $this->messages);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_pending_update_uses_committed_admin_notification_and_queued_snapshot_without_duplicate_submission(): void
    {
        config(['queue.default' => 'database']);
        $reservation = $this->reservation(['status' => 'pending']);
        $payload = ['reservation_date' => today()->addDays(2)->toDateString(), 'start_time' => '22:00', 'end_time' => '02:00', 'end_next_day' => 1, 'purpose' => 'Corrected workshop', 'attendees' => 10];
        DB::transaction(function () use ($reservation, $payload) {
            $this->actingAs($this->resident)->patchJson(route('reservations.update-pending', $reservation), $payload)->assertOk();
            $this->assertDatabaseCount('notifications', 1);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertCount(0, $this->messages);
        });
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('pending_updated', $this->admin->notifications()->firstOrFail()->data['event']);
        $this->assertSame(0, $this->resident->notifications()->count());
        $this->assertSame(0, $this->otherAdmin->notifications()->count());
        $this->patchJson(route('reservations.update-pending', $reservation), $payload)->assertOk();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 1);
        $reservation->refresh()->update(['status' => 'accepted', 'start_time' => '05:00']);
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $this->assertCount(1, $this->messages);
        $mail = $this->messages[0];
        $this->assertSame($this->admin->email, $mail->getTo()[0]->getAddress());
        $this->assertSame('eReserve - Pending Reservation Updated', $mail->getSubject());
        $this->assertStringContainsString('Pending', $mail->getHtmlBody());
        $this->assertStringContainsString('10:00 PM', $mail->getHtmlBody());
        $this->assertStringNotContainsString('₱1,250.25', $mail->getHtmlBody());
        $this->assertStringContainsString('https://ereserve.example.test/reservations?reservation='.$reservation->id, $mail->getHtmlBody());
    }

    public function test_pending_update_queued_mail_rechecks_admin_role_and_tenant(): void
    {
        config(['queue.default' => 'database']);
        $reservation = $this->reservation(['status' => 'pending']);
        $this->actingAs($this->resident)->patchJson(route('reservations.update-pending', $reservation), ['reservation_date' => today()->addDays(2)->toDateString(), 'start_time' => '22:00', 'end_time' => '02:00', 'end_next_day' => 1, 'purpose' => 'Corrected workshop', 'attendees' => 10])->assertOk();
        $this->admin->update(['barangay' => 'Washington']);
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $this->assertCount(0, $this->messages);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_failed_business_workflow_does_not_send_or_enqueue_mail(): void
    {
        config(['queue.default' => 'database']);
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel === 'database') {
                throw new \RuntimeException('Business transaction failed');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->accept($reservation);
            $this->fail('Expected workflow failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Business transaction failed', $exception->getMessage());
        }
        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(0, $this->messages);
    }

    public function test_database_queue_keeps_bell_immediate_and_mail_snapshot_survives_later_changes(): void
    {
        config(['queue.default' => 'database']);
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->accept($reservation);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertCount(0, $this->messages);
        $reservation->fresh()->update(['status' => 'cancelled', 'total_payment' => '99.00', 'reservation_date' => today()->addDays(5)->toDateString()]);
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $this->assertSame(5, $job->maxTries());
        $job->fire();
        $job->delete();
        $html = $this->residentMessage('eReserve - Reservation Confirmed')->getHtmlBody();
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringContainsString('₱1,250.25', $html);
        $this->assertStringContainsString(today()->addDays(2)->format('F j, Y'), $html);
        $this->assertStringNotContainsString('Cancelled', $html);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queued_admin_notification_is_suppressed_after_admin_changes_barangay(): void
    {
        config(['queue.default' => 'database']);
        $this->submit();
        $this->admin->update(['barangay' => 'Washington']);
        while ($job = Queue::connection('database')->pop()) {
            $job->fire();
            $job->delete();
        }
        $this->assertCount(1, $this->messages);
        $this->residentMessage('eReserve - Reservation Submitted');
    }

    public function test_queue_enqueue_failure_is_logged_and_keeps_committed_reservation_and_bell(): void
    {
        config(['queue.default' => 'database']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('private queue connection details'));
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->accept($reservation);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertCount(0, $this->messages);
        Log::shouldHaveReceived('error')->once()->with('Reservation email enqueue failed.', \Mockery::on(
            fn ($context) => $context['event'] === 'accepted' && $context['retry_recorded'] === false
                && ! str_contains(json_encode($context), 'private queue')
        ));
    }

    public function test_sync_default_cannot_send_submission_or_approval_mail_in_the_request(): void
    {
        config(['queue.default' => 'sync']);
        $this->mock(MailChannel::class)->shouldNotReceive('send');
        $reservation = $this->submit();
        $this->accept($reservation);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertSame('paid', $reservation->payment()->firstOrFail()->payment_status);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('jobs', 3);
        $this->assertCount(0, $this->messages);
        Log::shouldHaveReceived('info')->with('Reservation email queued.', \Mockery::on(
            fn ($context) => $context['connection'] === 'database' && $context['queue'] === 'default'
        ))->times(3);
    }

    public function test_async_transport_failure_can_retry_mail_without_repeating_database_notification(): void
    {
        config(['queue.default' => 'database']);
        $this->mock(MailChannel::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('private SMTP authentication details'));
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->accept($reservation);
        $job = Queue::connection('database')->pop();
        try {
            $job->fire();
            $this->fail('Expected temporary mail failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Reservation email delivery failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->app->forgetInstance(MailChannel::class);
        $this->app->make(ChannelManager::class)->forgetDrivers();
        $job->fire();
        $job->delete();
        $this->residentMessage('eReserve - Reservation Confirmed');
        $this->assertDatabaseCount('notifications', 1);
    }

    private function workMailQueue(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0])
            ->assertSuccessful();
    }

    private function drainMailQueue(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])
            ->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_custom_database_queue_is_used_by_mail_dispatch_and_the_development_listener(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.database.queue' => 'reservation-mail']);
        $reservation = $this->submit();
        $this->accept($reservation);
        $this->assertDatabaseCount('jobs', 3);
        $this->assertSame(3, DB::table('jobs')->where('queue', 'reservation-mail')->count());
        $this->assertCount(0, $this->messages);

        // Exercise Laravel's listener queue selection without starting a background process.
        $listener = \Mockery::mock(Listener::class);
        $listener->shouldReceive('setOutputHandler')->once();
        $listener->shouldReceive('listen')->once()->with('database', 'reservation-mail', \Mockery::type(ListenerOptions::class));
        $command = new ListenCommand($listener);
        $this->app->make(Kernel::class)->registerCommand($command);
        $this->artisan('queue:listen', ['connection' => 'database'])->assertSuccessful();

        $this->drainMailQueue();
        $this->assertCount(3, $this->messages);
        $this->assertDatabaseCount('notifications', 3);
        $this->residentMessage('eReserve - Reservation Submitted');
        $this->residentMessage('eReserve - Reservation Confirmed');
    }

    public function test_submission_is_queued_without_smtp_and_worker_logs_each_delivery_without_private_content(): void
    {
        config(['queue.default' => 'database']);
        $reservation = $this->submit();
        $this->assertCount(0, $this->messages);
        $this->assertDatabaseCount('jobs', 2);
        foreach (DB::table('jobs')->get() as $row) {
            $this->assertSame('default', $row->queue);
            $payload = json_decode($row->payload, true);
            $this->assertSame(5, $payload['maxTries']);
            $this->assertSame(60, $payload['timeout']);
            $this->assertSame('10,60,180,600', $payload['backoff']);
        }
        $this->workMailQueue();
        $this->workMailQueue();
        $this->assertCount(2, $this->messages);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 2);
        $this->residentMessage('eReserve - Reservation Submitted');
        foreach (['dispatching', 'queued', 'processing', 'accepted by transport', 'processed'] as $stage) {
            Log::shouldHaveReceived('info')->with('Reservation email '.$stage.'.', \Mockery::on(
                fn ($context) => $context['reservation_id'] === $reservation->id
                    && filled($context['notification_id']) && filled($context['recipient_id'])
                    && ! str_contains(json_encode($context), '@')
                    && ! str_contains(json_encode($context), 'Workshop')
            ))->twice();
        }
        $this->assertNotNull($this->resident->notifications()->first()->data['mail_sent_at'] ?? null);
        $this->getJson(route('notifications.index'))->assertJsonMissingPath('notifications.data.0.data.mail_sent_at');
    }

    public function test_native_enqueue_failure_is_saved_for_retry_without_repeating_the_business_action(): void
    {
        config(['queue.default' => 'database']);
        $failEnqueue = true;
        Event::listen(JobQueueing::class, function (JobQueueing $event) use (&$failEnqueue) {
            if ($failEnqueue && $event->job->notification instanceof ReservationActivity) {
                throw new \RuntimeException('private queue connection details');
            }
        });
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->accept($reservation);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $failed = DB::table('failed_jobs')->first();
        $this->assertStringNotContainsString('private queue', $failed->exception);
        Log::shouldHaveReceived('error')->with('Reservation email enqueue failed.', \Mockery::on(
            fn ($context) => $context['retry_recorded'] && $context['failed_job_id'] === $failed->uuid
        ));
        $failEnqueue = false;
        $this->artisan('queue:retry', ['id' => [$failed->uuid]])->assertSuccessful();
        $this->workMailQueue();
        $this->residentMessage('eReserve - Reservation Confirmed');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_worker_retries_a_transient_smtp_failure_then_sends_once(): void
    {
        config(['queue.default' => 'database']);
        $this->mock(MailChannel::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('535 private SMTP credentials'));
        $reservation = $this->reservation(['status' => 'pending']);
        $this->accept($reservation);
        $this->workMailQueue();
        $row = DB::table('jobs')->first();
        $this->assertSame(1, $row->attempts);
        $this->assertGreaterThanOrEqual(now()->timestamp + 9, $row->available_at);
        $this->assertDatabaseCount('failed_jobs', 0);
        Log::shouldHaveReceived('warning')->with('Reservation email attempt failed.', \Mockery::on(
            fn ($context) => $context['attempt'] === 1 && $context['failure_category'] === 'authentication'
                && ! str_contains(json_encode($context), 'private SMTP')
        ));
        $this->app->forgetInstance(MailChannel::class);
        $this->app->make(ChannelManager::class)->forgetDrivers();
        $this->travel(11)->seconds();
        $this->workMailQueue();
        $this->residentMessage('eReserve - Reservation Confirmed');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_exhausted_mail_retries_are_recorded_without_provider_secrets(): void
    {
        config(['queue.default' => 'database']);
        $this->mock(MailChannel::class)->shouldReceive('send')->times(5)->andThrow(new \RuntimeException('535 secret-password private SMTP transcript'));
        $reservation = $this->reservation(['status' => 'pending']);
        $this->accept($reservation);
        foreach ([11, 61, 181, 601, 0] as $delay) {
            $this->workMailQueue();
            $this->travel($delay)->seconds();
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $failure = DB::table('failed_jobs')->first();
        $this->assertStringContainsString('Reservation email delivery failed.', $failure->exception);
        $this->assertStringNotContainsString('secret-password', $failure->exception);
        $this->assertStringNotContainsString('private SMTP', $failure->exception);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertCount(0, $this->messages);
        Log::shouldHaveReceived('warning')->with('Reservation email delivery failed.', \Mockery::type('array'));
    }

    public function test_replayed_successful_job_does_not_send_a_duplicate_email(): void
    {
        config(['queue.default' => 'database']);
        $this->accept($this->reservation(['status' => 'pending']));
        $payload = DB::table('jobs')->value('payload');
        Queue::connection('database')->pushRaw($payload, 'default');
        $this->workMailQueue();
        $this->workMailQueue();
        $this->residentMessage('eReserve - Reservation Confirmed');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        Log::shouldHaveReceived('info')->with('Reservation email skipped.', \Mockery::on(fn ($context) => $context['reason'] === 'already_sent'));
    }

    public function test_log_sink_failure_after_smtp_acceptance_does_not_retry_the_email(): void
    {
        config(['queue.default' => 'database']);
        Log::shouldReceive('info')->andReturnUsing(function ($message) {
            if ($message === 'Reservation email accepted by transport.') {
                throw new \RuntimeException('private logging service details');
            }
        });
        $this->accept($this->reservation(['status' => 'pending']));
        $this->workMailQueue();
        $this->residentMessage('eReserve - Reservation Confirmed');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertNotNull($this->resident->notifications()->first()->data['mail_sent_at'] ?? null);
    }

    public function test_failure_record_storage_outage_is_reported_without_undoing_the_reservation(): void
    {
        config(['queue.default' => 'database']);
        Event::listen(JobQueueing::class, fn () => throw new \RuntimeException('private queue details'));
        $failedJobs = \Mockery::mock(FailedJobProviderInterface::class);
        $failedJobs->shouldReceive('log')->once()->andThrow(new \RuntimeException('private failed storage details'));
        $this->app->instance('queue.failer', $failedJobs);
        $reservation = $this->reservation(['status' => 'pending']);
        $this->accept($reservation);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        Log::shouldHaveReceived('error')->with('Reservation email failure could not be stored.', \Mockery::on(
            fn ($context) => ! str_contains(json_encode($context), 'private')
        ));
        Log::shouldHaveReceived('error')->with('Reservation email enqueue failed.', \Mockery::on(
            fn ($context) => $context['retry_recorded'] === false
        ));
    }

    public function test_worker_and_smtp_timeouts_leave_room_before_database_queue_redelivery(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $script = implode(' ', $composer['scripts']['dev']);
        $this->assertSame(1, substr_count($script, 'queue:listen database '));
        $this->assertStringNotContainsString('queue:work', $script);
        $this->assertStringNotContainsString('--queue=', $script);
        $this->assertMatchesRegularExpression('/queue:listen[^\"]*--timeout=([1-9][0-9]*)/', $script);
        preg_match('/queue:listen[^\"]*--timeout=([1-9][0-9]*)/', $script, $matches);
        $listenerTimeout = (int) $matches[1];
        $notification = new ReservationActivity($this->reservation(), 'accepted');
        $this->assertGreaterThan(0, config('mail.mailers.smtp.timeout'));
        $this->assertLessThan($notification->timeout, config('mail.mailers.smtp.timeout'));
        $this->assertLessThan($listenerTimeout, $notification->timeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $listenerTimeout);
        $process = app('queue.listener')->makeProcess('database', 'default', new ListenerOptions(timeout: $listenerTimeout));
        $this->assertSame((float) $listenerTimeout, $process->getTimeout());
        $this->assertStringContainsString('--restart-tries=-1', $script);
    }
}
