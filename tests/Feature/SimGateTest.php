<?php

namespace Tests\Feature;

use App\Services\SimGateService;
use App\Jobs\SendPaymentReceiptSms;
use App\Models\Facility;
use App\Models\PaymentSmsNotification;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SimGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_philippine_numbers_are_normalized_and_documented_payload_is_sent_from_configured_device(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'device-123', 'services.simgate.base_url' => 'https://api.simgate.app']);
        Http::fake(['api.simgate.app/v1/sms/send' => Http::response(['success' => true, 'messageId' => 'message-123', 'queued' => false, 'connectionStatus' => 'online', 'message' => 'SMS command accepted by device.'], 200)]);

        $result = app(SimGateService::class)->send('09171234567', 'Receipt message');

        $this->assertSame(['status' => 'accepted', 'uid' => 'message-123', 'error' => null], $result);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.simgate.app/v1/sms/send'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request['phoneNumber'] === '+639171234567'
            && $request['deviceId'] === 'device-123'
            && $request['message'] === 'Receipt message');
    }

    public function test_invalid_number_and_missing_credentials_fail_without_request(): void
    {
        Http::fake();
        config(['services.simgate.api_key' => '', 'services.simgate.device_id' => '']);
        $service = app(SimGateService::class);

        $this->assertSame('invalid_mobile_number', $service->send('12345', 'msg')['error']);
        $this->assertSame('missing_configuration', $service->send('09171234567', 'msg')['error']);
        Http::assertNothingSent();
    }

    public function test_invalid_device_is_recorded_as_gateway_unavailable(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'device-404']);
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Device not registered.'], 404)]);

        $this->assertSame('gateway_unavailable', app(SimGateService::class)->send('+639171234567', 'msg')['error']);
    }

    public function test_invalid_api_key_and_provider_errors_are_not_reported_as_accepted(): void
    {
        config(['services.simgate.api_key' => 'bad-key', 'services.simgate.device_id' => 'device-123']);
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid API key.'], 401)]);

        $this->assertSame('invalid_api_key', app(SimGateService::class)->send('+639171234567', 'msg')['error']);
    }

    public function test_http_error_logs_only_status_and_safe_timing_diagnostics(): void
    {
        config(['services.simgate.api_key' => 'api-key-sentinel', 'services.simgate.device_id' => 'device-123']);
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'private provider response sentinel'], 401)]);
        Log::spy();

        $result = app(SimGateService::class)->send('09171234567', 'private sms body sentinel');

        $this->assertSame('invalid_api_key', $result['error']);
        Log::shouldHaveReceived('warning')->once()->with('SimGate SMS API returned an error.', \Mockery::on(
            fn ($context) => $context['http_status'] === 401
                && is_int($context['elapsed_ms'])
                && ! str_contains(json_encode($context), 'api-key-sentinel')
                && ! str_contains(json_encode($context), 'private provider response sentinel')
                && ! str_contains(json_encode($context), 'private sms body sentinel')
                && ! str_contains(json_encode($context), '09171234567')
        ));
    }

    public function test_timeout_phase_is_classified_from_safe_guzzle_handler_context(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'device-123']);
        $request = new GuzzleRequest('POST', 'https://api.simgate.app/v1/sms/send');
        $connectionTimeout = new ConnectionException('cURL error 28: operation timed out', 0,
            new GuzzleConnectException('transport failed', $request, null, ['errno' => 28, 'total_time' => 5.0]));
        Http::fake(fn () => throw $connectionTimeout);
        Log::spy();

        $this->assertSame('unknown', app(SimGateService::class)->send('09171234567', 'safe message')['status']);
        Log::shouldHaveReceived('warning')->once()->with('SimGate SMS transport failed; outcome unknown.', \Mockery::on(
            fn ($context) => $context['curl_error_code'] === 28
                && $context['timeout_phase'] === 'connection'
                && is_int($context['elapsed_ms'])
                && $context['http_status'] === null
        ));
    }

    public function test_overall_request_timeout_phase_is_classified_without_logging_handler_context(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'device-123']);
        $request = new GuzzleRequest('POST', 'https://api.simgate.app/v1/sms/send');
        $requestTimeout = new ConnectionException('cURL error 28: operation timed out', 0,
            new GuzzleRequestException('transport failed', $request, null, null, [
                'errno' => 28, 'http_code' => 0, 'connect_time' => 0.1, 'total_time' => 15.0,
                'url' => 'https://api.simgate.app/v1/sms/send?private=sentinel',
            ]));
        Http::fake(fn () => throw $requestTimeout);
        Log::spy();

        $this->assertSame('unknown', app(SimGateService::class)->send('09171234567', 'safe message')['status']);
        Log::shouldHaveReceived('warning')->once()->with('SimGate SMS transport failed; outcome unknown.', \Mockery::on(
            fn ($context) => $context['curl_error_code'] === 28
                && $context['timeout_phase'] === 'overall_request'
                && ! str_contains(json_encode($context), 'api.simgate.app')
                && ! str_contains(json_encode($context), 'private=sentinel')
        ));
    }

    public function test_offline_queued_response_is_accepted_but_not_marked_delivered(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'device-123']);
        Http::fake(['*' => Http::response(['success' => true, 'messageId' => 'message-queued', 'queued' => true, 'connectionStatus' => 'offline', 'message' => 'Queued for device.'], 202)]);

        $this->assertSame(['status' => 'accepted', 'uid' => 'message-queued', 'error' => null], app(SimGateService::class)->send('+639171234567', 'msg'));
    }

    public function test_timeout_is_unknown_and_not_claimed_as_delivery(): void
    {
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'device-123']);
        Http::fake(fn () => throw new ConnectionException('connection timed out'));

        $this->assertSame(['status' => 'unknown', 'uid' => null, 'error' => 'transport_timeout_unknown'], app(SimGateService::class)->send('09171234567', 'msg'));
    }

    #[DataProvider('transportErrors')]
    public function test_transport_errors_are_categorized_and_logged_without_sensitive_details(string $exceptionClass, string $message, string $category, string $failureType, ?int $curlCode): void
    {
        config(['services.simgate.api_key' => 'secret-api-key-sentinel', 'services.simgate.device_id' => 'device-123']);
        Http::fake(fn () => throw new $exceptionClass($message));
        Log::spy();

        $result = app(SimGateService::class)->send('09171234567', 'private sms body sentinel');

        $this->assertSame('unknown', $result['status']);
        $this->assertSame($category, $result['error']);
        Log::shouldHaveReceived('warning')->once()->with('SimGate SMS transport failed; outcome unknown.', \Mockery::on(
            fn ($context) => $context['exception_class'] === $exceptionClass
                && $context['error_category'] === $category
                && $context['transport_failure_type'] === $failureType
                && $context['curl_error_code'] === $curlCode
                && ! str_contains(json_encode($context), 'secret-api-key-sentinel')
                && ! str_contains(json_encode($context), 'private sms body sentinel')
                && ! str_contains(json_encode($context), '09171234567')
        ));
    }

    public static function transportErrors(): array
    {
        return [
            'timeout' => [ConnectionException::class, 'cURL error 28: operation timed out', 'transport_timeout_unknown', 'timeout', 28],
            'connection failure' => [ConnectionException::class, 'cURL error 7: failed to connect: connection refused', 'transport_connection_unknown', 'connection', 7],
            'DNS failure' => [ConnectionException::class, 'cURL error 6: could not resolve host', 'transport_dns_unknown', 'dns', 6],
            'SSL failure' => [ConnectionException::class, 'cURL error 60: SSL certificate problem', 'transport_ssl_unknown', 'tls', 60],
            'other transport exception without cURL code' => [\RuntimeException::class, 'transport layer interrupted', 'transport_error_unknown', 'other', null],
        ];
    }

    public function test_receipt_job_sends_actual_details_and_records_provider_acceptance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 14:30:00'));
        config(['services.simgate.api_key' => 'test-key', 'services.simgate.device_id' => 'device-123']);
        Http::fake(['*' => Http::response(['success' => true, 'messageId' => 'message-456', 'queued' => false, 'connectionStatus' => 'online'], 200)]);
        $resident = User::factory()->create(['name' => 'Tracy Jann Wanelly Alcero', 'phone_number' => '09171234567']);
        $facility = Facility::create(['barangay' => 'Washington', 'slug' => 'main-function-room', 'name' => 'Barangay Hall Main Function Room', 'category' => 'Facility', 'description' => 'Function Room', 'capacity' => 100, 'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => 100]);
        $reservation = Reservation::create(['user_id' => $resident->id, 'barangay' => 'Washington', 'facility_id' => $facility->id, 'facility_slug' => 'main-function-room', 'facility_name' => 'Barangay Hall Main Function Room', 'category' => 'Facility', 'location' => 'Washington', 'reservation_date' => '2026-11-26', 'start_time' => '00:00', 'end_time' => '01:00', 'purpose' => 'Meeting', 'attendees' => 10, 'status' => 'accepted', 'total_payment' => '100.00']);
        $payment = $reservation->payment()->create(['amount' => '100.00', 'payment_status' => 'paid', 'receipt_reference' => 'ER-2026-0005', 'paid_at' => now()]);
        PaymentSmsNotification::create(['payment_id' => $payment->id, 'status' => 'pending']);

        $job = new SendPaymentReceiptSms($payment->id);
        $job->handle(app(SimGateService::class));
        $job->handle(app(SimGateService::class));

        $record = PaymentSmsNotification::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('accepted', $record->status);
        $this->assertSame('message-456', $record->provider_uid);
        $this->assertFalse(Schema::hasColumn('payment_sms_notifications', 'delivered_at'));
        $this->assertSame('+639171234567', app(SimGateService::class)->normalizeRecipient('09171234567'));
        Http::assertSentCount(1);
        $sent = Http::recorded(fn ($request) => true)->first()[0] ?? null;
        $this->assertNotNull($sent, 'No request recorded');
        $expectedMessage = "eReserve - Washington\nPAYMENT RECEIPT\n\nHello, Tracy Jann Wanelly Alcero!\n\nReceipt No: ER-2026-0005\n\nRESERVATION DETAILS\nFacility: Barangay Hall Main Function Room\nDate: November 26, 2026\nTime: 12:00 AM - 1:00 AM\n\nPAYMENT DETAILS\nAmount Paid: PHP 100.00\nPayment Date: November 25, 2026\n\nThank you for using eReserve!";
        $this->assertSame($expectedMessage, $sent['message']);
        $this->assertSame(0, preg_match('/[^\x00-\x7F]/', $sent['message']));
        $record->update(['status' => 'unknown', 'error_category' => 'transport_timeout_unknown']);
        $job->handle(app(SimGateService::class));
        $this->assertSame('unknown', $record->fresh()->status);
        Http::assertSentCount(1);
    }
}
