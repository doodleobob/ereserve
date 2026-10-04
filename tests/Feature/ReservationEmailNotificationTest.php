<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationActivity;
use App\Support\OfficialUseScheduling;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSent;
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
            'action' => 'reschedule', 'reservation_date' => $date ?? today()->addDays(3)->toDateString(), 'start_time' => '22:00', 'end_time' => '02:00',
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
        $html = $this->residentMessage('eReserve - Reservation Confirmed')->getHtmlBody();
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringContainsString('₱1,250.25', $html);
        $this->assertStringNotContainsString('₱7.00', $html);
        $this->assertStringNotContainsString('Amount', $html);
        $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), ['total_payment' => '1250.25', 'payment_confirmed' => 1])->assertUnprocessable();
        $this->assertCount(1, $this->messages);
    }

    public function test_rejection_sends_email_and_in_app_without_inventing_a_reason(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->actingAs($this->admin)->postJson(route('reservations.reject', $reservation), ['rejection_confirmed' => 1])->assertOk();
        $html = $this->residentMessage('eReserve - Reservation Update')->getHtmlBody();
        $this->assertStringContainsString('has been rejected', $html);
        $this->assertStringContainsString('Rejected', $html);
        $this->assertStringNotContainsString('Reason:', $html);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'rejected')->count());
        $this->postJson(route('reservations.reject', $reservation), ['rejection_confirmed' => 1])->assertUnprocessable();
        $this->assertCount(1, $this->messages);
    }

    public function test_cancellation_retains_paid_payment_and_email_never_claims_a_refund(): void
    {
        $reservation = $this->reservation();
        $payment = $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'paid']);
        $this->cancel($reservation);
        $html = $this->residentMessage('eReserve - Reservation Cancelled')->getHtmlBody();
        $this->assertStringContainsString('Cancelled', $html);
        $this->assertStringContainsString('Official Use', $html);
        $this->assertStringContainsString('Barangay assembly.', $html);
        $this->assertStringNotContainsString('refund', strtolower($html));
        $this->assertSame('paid', $payment->fresh()->payment_status);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'cancelled')->count());
    }

    public function test_rescheduling_sends_new_and_previous_overnight_schedule_without_creating_payment(): void
    {
        $reservation = $this->reservation();
        $payment = $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'paid']);
        $before = $payment->fresh()->toArray();
        $this->reschedule($reservation);
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
        $this->assertCount(1, $this->messages);
    }

    public function test_official_use_conflict_preserves_accepted_status_and_does_not_renotify_on_reads_recalculation_or_same_edit(): void
    {
        $reservation = $this->reservation();
        $use = $this->officialUse($reservation);
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
        $this->assertCount(1, $this->messages);
        $this->assertSame(1, $this->resident->notifications()->count());
    }

    public function test_official_use_pending_cancellation_sends_email_without_payment_or_refund(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->officialUse($reservation);
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
        $this->assertCount(0, $this->messages);
        $data = ['facility_id' => $this->facility->id, 'date' => $reservation->reservation_date, 'start_time' => '12:00', 'end_time' => '16:00', 'purpose' => $use->purpose];
        $this->patchJson(route('official-uses.update', $use), $data)->assertOk();
        $this->residentMessage('eReserve - Official Use Schedule Conflict');
        $this->patchJson(route('official-uses.update', $use), $data)->assertOk();
        $this->assertCount(1, $this->messages);
        $this->patchJson(route('official-uses.update', $use), [...$data, 'date' => today()->addDays(4)->toDateString()])->assertOk();
        $this->patchJson(route('official-uses.update', $use), $data)->assertOk();
        $this->assertCount(2, $this->messages);
        $this->assertSame('accepted', $reservation->fresh()->status);
    }

    public function test_official_use_reschedule_resolution_sends_only_one_final_email(): void
    {
        $reservation = $this->reservation();
        $use = $this->officialUse($reservation);
        $conflict = $use->conflicts()->firstOrFail();
        $this->actingAs($this->resident)->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'reschedule'])->assertOk();
        $this->assertCount(1, $this->messages);
        $this->reschedule($reservation);
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
        $this->cancel($reservation);
        $this->residentMessage('eReserve - Reservation Cancelled');
        $this->assertCount(2, $this->messages);
        $this->assertSame('resolved', $conflict->fresh()->resolution);
    }

    public function test_paid_to_paid_is_silent_and_refund_notifies_once_using_reservation_total(): void
    {
        $reservation = $this->reservation(['status' => 'cancelled']);
        $payment = $reservation->payment()->create(['amount' => '7.00', 'payment_status' => 'paid']);
        $this->actingAs($this->admin)->patchJson(route('payments.update', $payment), ['payment_status' => 'paid'])->assertOk();
        $this->assertCount(0, $this->messages);
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded', 'receipt_confirmed' => 0])->assertUnprocessable();
        $this->assertCount(0, $this->messages);
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded', 'receipt_confirmed' => 1])->assertOk();
        $html = $this->residentMessage('eReserve - Payment Refunded')->getHtmlBody();
        $this->assertStringContainsString('₱1,250.25', $html);
        $this->assertStringNotContainsString('₱7.00', $html);
        $this->assertStringNotContainsString('Amount', $html);
        $this->assertStringContainsString('Payment Status', $html);
        $this->assertSame(1, $this->resident->notifications()->where('data->event', 'payment_refunded')->count());
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded'])->assertOk();
        $this->get(route('payments.index'))->assertOk();
        $this->get(route('payments.index'))->assertOk();
        $this->assertCount(1, $this->messages);
    }

    public function test_total_correction_notifies_only_after_a_meaningful_change(): void
    {
        $reservation = $this->reservation();
        $this->actingAs($this->admin)->patchJson(route('reservations.payment', $reservation), ['total_payment' => '1300'])->assertOk();
        $html = $this->residentMessage('eReserve - Total Paid Updated')->getHtmlBody();
        $this->assertStringContainsString('₱1,300.00', $html);
        $this->assertStringContainsString('₱1,250.25', $html);
        $this->patchJson(route('reservations.payment', $reservation), ['total_payment' => '1300.00'])->assertOk();
        $this->assertCount(1, $this->messages);
    }

    public function test_mail_waits_for_commit_and_rollback_discards_both_channels(): void
    {
        $reservation = $this->reservation();
        DB::transaction(function () use ($reservation) {
            $this->resident->notify(new ReservationActivity($reservation, 'accepted'));
            $this->assertCount(0, $this->messages);
            $this->assertDatabaseCount('notifications', 1);
        });
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
        $this->assertSame(3, $job->maxTries());
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
        Log::shouldReceive('warning')->once()->with('Reservation email delivery failed.', \Mockery::on(fn ($context) => $context['event'] === 'accepted' && ! str_contains(json_encode($context), 'private queue')));
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->accept($reservation);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertCount(0, $this->messages);
    }

    public function test_sync_transport_failure_is_logged_without_secrets_and_does_not_undo_acceptance(): void
    {
        $this->mock(MailChannel::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('private provider credentials'));
        Log::shouldReceive('warning')->once()->with('Reservation email delivery failed.', \Mockery::on(fn ($context) => $context['event'] === 'accepted' && ! str_contains(json_encode($context), 'private provider')));
        $reservation = $this->reservation(['status' => 'pending', 'total_payment' => null]);
        $this->accept($reservation);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertSame('paid', $reservation->payment()->firstOrFail()->payment_status);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertCount(0, $this->messages);
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
}
