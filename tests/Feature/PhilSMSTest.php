<?php

namespace Tests\Feature;

use App\Services\PhilSMSService;
use App\Jobs\SendPaymentReceiptSms;
use App\Models\Facility;
use App\Models\PaymentSmsNotification;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhilSMSTest extends TestCase
{
    use RefreshDatabase;

    public function test_philippine_numbers_are_normalized_and_documented_payload_is_sent(): void
    {
        config(['services.philsms.token' => 'test-token', 'services.philsms.sender_id' => 'eReserve', 'services.philsms.base_url' => 'https://app.philsms.com']);
        Http::fake(['app.philsms.com/api/v3/sms/send' => Http::response(['status' => 'success', 'data' => ['uid' => 'msg-123']], 200)]);

        $result = app(PhilSMSService::class)->send('09171234567', 'Receipt message');

        $this->assertSame(['status' => 'accepted', 'uid' => 'msg-123', 'error' => null], $result);
        Http::assertSent(fn ($request) => $request->url() === 'https://app.philsms.com/api/v3/sms/send'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['recipient'] === '639171234567'
            && $request['sender_id'] === 'eReserve'
            && $request['type'] === 'plain'
            && $request['message'] === 'Receipt message');
    }

    public function test_invalid_number_and_missing_credentials_fail_without_request(): void
    {
        Http::fake();
        config(['services.philsms.token' => '', 'services.philsms.sender_id' => '']);
        $service = app(PhilSMSService::class);

        $this->assertSame('invalid_mobile_number', $service->send('12345', 'msg')['error']);
        $this->assertSame('missing_configuration', $service->send('09171234567', 'msg')['error']);
        Http::assertNothingSent();
    }

    public function test_provider_error_is_not_reported_as_delivery(): void
    {
        config(['services.philsms.token' => 'test-token', 'services.philsms.sender_id' => 'eReserve']);
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Insufficient credits'], 422)]);

        $this->assertSame('insufficient_credits', app(PhilSMSService::class)->send('+639171234567', 'msg')['error']);
    }

    public function test_receipt_job_sends_actual_details_and_records_provider_acceptance(): void
    {
        config(['services.philsms.token' => 'test-token', 'services.philsms.sender_id' => 'eReserve']);
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['uid' => 'msg-456']], 200)]);
        $resident = User::factory()->create(['name' => 'Juan Dela Cruz', 'phone_number' => '09171234567']);
        $facility = Facility::create(['barangay' => 'Taft', 'slug' => 'court', 'name' => 'Covered Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 20, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
        $reservation = Reservation::create(['user_id' => $resident->id, 'barangay' => 'Taft', 'facility_id' => $facility->id, 'facility_slug' => 'court', 'facility_name' => 'Covered Court', 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => '2026-12-10', 'start_time' => '09:00', 'end_time' => '10:00', 'purpose' => 'Meeting', 'attendees' => 10, 'status' => 'accepted', 'total_payment' => '500.00']);
        $payment = $reservation->payment()->create(['amount' => '500.00', 'payment_status' => 'paid', 'receipt_reference' => 'ER-2026-0099', 'paid_at' => now()]);
        PaymentSmsNotification::create(['payment_id' => $payment->id, 'status' => 'pending']);

        (new SendPaymentReceiptSms($payment->id))->handle(app(PhilSMSService::class));

        $record = PaymentSmsNotification::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('accepted', $record->status);
        $this->assertSame('msg-456', $record->provider_uid);
        $this->assertFalse(Schema::hasColumn('payment_sms_notifications', 'delivered_at'));
        $this->assertSame('639171234567', app(PhilSMSService::class)->normalizeRecipient('09171234567'));
        $sent = Http::recorded(fn ($request) => true)->first()[0] ?? null;
        $this->assertNotNull($sent, 'No request recorded');
        $this->assertStringContainsString('Juan Dela Cruz', $sent['message']);
        $this->assertStringContainsString('ER-2026-0099', $sent['message']);
        $this->assertStringContainsString('Covered Court', $sent['message']);
        $this->assertStringContainsString('Dec 10, 2026', $sent['message']);
        $this->assertStringContainsString('₱500.00', $sent['message']);
    }
}
