<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationSms;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\Channels\QueuedSimGateSms;
use App\Notifications\ReservationActivity;
use App\Notifications\SecurityCode;
use App\Support\PasswordResetSmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailNotificationSmsCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resident = User::factory()->create([
            'name' => 'Tracy Wanelly Alcero', 'phone_number' => '09171234567',
            'barangay' => 'Washington',
        ]);
        $facility = Facility::create([
            'barangay' => 'Washington', 'slug' => 'main-hall', 'name' => 'Barangay Hall Main Function Room',
            'category' => 'Facility', 'description' => 'Hall', 'capacity' => 100, 'location' => 'Washington',
            'status' => 'Available', 'hourly_rate' => 100, 'reservation_access' => 'all_registered_users',
        ]);
        $this->reservation = Reservation::create([
            'user_id' => $this->resident->id, 'facility_id' => $facility->id, 'barangay' => 'Washington',
            'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => 'Facility',
            'location' => 'Washington', 'reservation_date' => '2026-11-26', 'start_time' => '00:00', 'end_time' => '01:00',
            'purpose' => 'Meeting', 'attendees' => 10, 'status' => 'accepted', 'hourly_rate_snapshot' => 100,
            'total_payment' => '100.00',
        ]);
    }

    public function test_every_reservation_email_event_uses_the_sms_channel_and_non_email_decision_stays_database_only(): void
    {
        $emailEvents = [
            'usage_check_in', 'usage_check_out', 'usage_release', 'usage_return', 'submitted',
            'pending_updated', 'pending_cancelled', 'submission_confirmed', 'accepted', 'rejected',
            'rescheduled', 'payment_refunded', 'payment_updated', 'cancelled',
            'official_use_cancelled', 'official_use_conflict',
        ];

        foreach ($emailEvents as $event) {
            $this->assertContains(QueuedSimGateSms::class, (new ReservationActivity($this->reservation, $event))->via($this->resident), $event);
        }
        $this->assertSame(['database'], (new ReservationActivity($this->reservation, 'official_use_decision'))->via($this->resident));
    }

    public function test_reschedule_and_conflict_sms_include_the_existing_schedules_and_next_action(): void
    {
        $rescheduled = new ReservationActivity($this->reservation, 'rescheduled', previousSchedule: [
            'reservation_date' => '2026-11-25', 'start_time' => '10:00', 'end_time' => '11:00',
        ]);
        $message = $rescheduled->toSms($this->resident);
        $this->assertStringContainsString('Hello, Tracy Wanelly Alcero!', $message);
        $this->assertStringContainsString('Old: November 25, 2026 10:00 AM - November 25, 2026 11:00 AM', $message);
        $this->assertStringContainsString('New: November 26, 2026 12:00 AM - November 26, 2026 1:00 AM', $message);
        $this->assertStringContainsString('Facility: Barangay Hall Main Function Room', $message);

        $conflict = (new ReservationActivity($this->reservation, 'official_use_conflict'))->toSms($this->resident);
        $this->assertStringContainsString('OFFICIAL USE SCHEDULE CONFLICT', $conflict);
        $this->assertStringContainsString('Please choose Reschedule or Cancellation in My Reservations.', $conflict);
        $this->assertStringContainsString('The administrator will process your choice.', $conflict);
    }

    public function test_admin_sms_uses_the_email_recipient_scope_for_submission_events(): void
    {
        $this->reservation->barangay = 'Washington';
        $this->reservation->save();
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington', 'phone_number' => '09170000001']);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft', 'phone_number' => '09170000002']);
        $notification = new ReservationActivity($this->reservation, 'submitted');

        $this->assertTrue($notification->shouldSend($admin, 'sms'));
        $this->assertFalse($notification->shouldSend($otherAdmin, 'sms'));
        $this->assertStringContainsString('Resident: Tracy Wanelly Alcero', $notification->toSms($admin));
    }

    public function test_sms_skip_logs_missing_mobile_without_logging_personal_data(): void
    {
        Log::spy();
        $notification = new ReservationActivity($this->reservation, 'submission_confirmed');
        $this->resident->phone_number = null;
        $this->resident->save();

        $this->assertFalse($notification->shouldSend($this->resident, 'sms'));
        Log::shouldHaveReceived('info')->once()->with('Reservation SMS skipped.', \Mockery::on(
            fn ($context) => $context['event'] === 'submission_confirmed'
                && $context['reason'] === 'missing_mobile_number'
                && ! str_contains(json_encode($context), 'phone_number')
        ));

    }

    public function test_sms_skip_logs_invalid_mobile_separately_without_logging_the_number(): void
    {
        Log::spy();
        $notification = new ReservationActivity($this->reservation, 'submission_confirmed');
        $this->resident->phone_number = '12345';
        $this->resident->save();

        $this->assertFalse($notification->shouldSend($this->resident, 'sms'));
        Log::shouldHaveReceived('info')->once()->with('Reservation SMS skipped.', \Mockery::on(
            fn ($context) => $context['event'] === 'submission_confirmed'
                && $context['reason'] === 'invalid_mobile_number'
                && ! str_contains(json_encode($context), '12345')
        ));
    }

    public function test_security_and_password_reset_emails_have_sms_messages_without_persisting_codes_or_tokens(): void
    {
        $security = new SecurityCode('123456', 'verify');
        $this->assertStringContainsString('123456', $security->toSms($this->resident));
        $this->assertStringNotContainsString('123456', json_encode($security->toDatabase($this->resident)));

        config(['app.url' => 'https://ereserve.example.test']);
        $reset = PasswordResetSmsMessage::make($this->resident, 'private-token');
        $this->assertStringContainsString('https://ereserve.example.test/reset-password/private-token', $reset);
        $this->assertStringNotContainsString('private-token', json_encode(['event' => 'password_reset_delivery']));
    }

    public function test_notification_sms_job_does_not_send_again_after_provider_acceptance(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'test-device']);
        Http::fake(['*' => Http::response(['success' => true, 'messageId' => 'provider-1', 'queued' => false], 200)]);
        $record = $this->resident->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ReservationActivity::class,
            'data' => ['event' => 'accepted', 'reservation_id' => $this->reservation->id, 'sms' => ['status' => 'pending']],
        ]);
        $job = new SendNotificationSms($record->id, User::class, (string) $this->resident->id, 'safe test message');

        $job->handle(app(\App\Services\SimGateService::class));
        $job->handle(app(\App\Services\SimGateService::class));

        Http::assertSentCount(1);
        $this->assertSame('accepted', $this->resident->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['status']);
        $this->assertSame('provider-1', $this->resident->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['provider_uid']);
    }

    public function test_queued_sms_content_is_encrypted_at_rest(): void
    {
        $job = new SendNotificationSms('notification-id', User::class, (string) $this->resident->id, 'private reset link sentinel');

        $this->assertStringNotContainsString('private reset link sentinel', serialize($job));
    }

    public function test_notification_sms_job_records_missing_phone_without_calling_provider(): void
    {
        $this->resident->phone_number = null;
        $this->resident->save();
        Http::fake();
        $record = $this->resident->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ReservationActivity::class,
            'data' => ['event' => 'accepted', 'reservation_id' => $this->reservation->id, 'sms' => ['status' => 'pending']],
        ]);

        (new SendNotificationSms($record->id, User::class, (string) $this->resident->id, 'safe test message'))
            ->handle(app(\App\Services\SimGateService::class));

        Http::assertNothingSent();
        $this->assertSame('failed', $this->resident->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['status']);
        $this->assertSame('missing_mobile_number', $this->resident->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['error']);
    }

    public function test_notification_sms_job_does_not_resend_an_ambiguous_provider_outcome(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'test-device']);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: operation timed out');
        });
        $record = $this->resident->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ReservationActivity::class,
            'data' => ['event' => 'accepted', 'reservation_id' => $this->reservation->id, 'sms' => ['status' => 'pending']],
        ]);
        $job = new SendNotificationSms($record->id, User::class, (string) $this->resident->id, 'safe test message');

        $job->handle(app(\App\Services\SimGateService::class));
        $job->handle(app(\App\Services\SimGateService::class));

        $this->assertSame(1, $attempts);
        $this->assertSame('unknown', $this->resident->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['status']);
    }

    public function test_worker_allows_an_admin_still_assigned_to_the_reservation_barangay(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'test-device']);
        Http::fake(['*' => Http::response(['success' => true, 'messageId' => 'admin-message', 'queued' => false], 200)]);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington', 'phone_number' => '09170000001']);
        $record = $this->adminSmsRecord($admin);

        (new SendNotificationSms($record->id, User::class, (string) $admin->id, 'safe test message'))
            ->handle(app(\App\Services\SimGateService::class));

        Http::assertSentCount(1);
        $this->assertSame('accepted', $admin->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['status']);
    }

    public function test_worker_skips_an_admin_whose_role_changed_after_queueing(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington', 'phone_number' => '09170000001']);
        $record = $this->adminSmsRecord($admin);
        $admin->update(['role' => 'user']);

        (new SendNotificationSms($record->id, User::class, (string) $admin->id, 'safe test message'))
            ->handle(app(\App\Services\SimGateService::class));

        Http::assertNothingSent();
        $this->assertSame('skipped', $admin->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['status']);
        $this->assertSame('recipient_no_longer_eligible', $admin->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['error']);
    }

    public function test_worker_skips_an_admin_reassigned_after_queueing(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington', 'phone_number' => '09170000001']);
        $record = $this->adminSmsRecord($admin);
        $admin->update(['barangay' => 'Taft']);

        (new SendNotificationSms($record->id, User::class, (string) $admin->id, 'safe test message'))
            ->handle(app(\App\Services\SimGateService::class));

        Http::assertNothingSent();
        $this->assertSame('skipped', $admin->notifications()->whereKey($record->id)->firstOrFail()->data['sms']['status']);
    }

    public function test_worker_skips_deleted_notification_recipient(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington', 'phone_number' => '09170000001']);
        $record = $this->adminSmsRecord($admin);
        $adminId = (string) $admin->id;
        $admin->delete();

        (new SendNotificationSms($record->id, User::class, $adminId, 'safe test message'))
            ->handle(app(\App\Services\SimGateService::class));

        Http::assertNothingSent();
        $data = json_decode(DB::table('notifications')->where('id', $record->id)->value('data'), true);
        $this->assertSame('skipped', $data['sms']['status']);
    }

    private function adminSmsRecord(User $admin): \Illuminate\Notifications\DatabaseNotification
    {
        return $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ReservationActivity::class,
            'data' => [
                'event' => 'submitted', 'reservation_id' => $this->reservation->id,
                'barangay' => 'Washington', 'sms' => ['status' => 'pending'],
            ],
        ]);
    }
}
