<?php

namespace Tests\Unit;

use App\Mail\Transports\GmailApiTransport;
use App\Mail\Transports\GmailApiTransportException;
use App\Mail\Transports\GoogleGmailApiSender;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Notifications\SecurityCode;
use App\Services\TwoFactorCodes;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

#[CoversClass(GmailApiTransport::class)]
#[CoversClass(GoogleGmailApiSender::class)]
class GmailApiTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_gmail_api_mailer_is_registered_without_changing_smtp_or_default(): void
    {
        config(['services.gmail' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
            'user' => 'sender@example.test',
        ]]);
        Mail::purge('gmail_api');

        $mailer = Mail::mailer('gmail_api');

        $this->assertInstanceOf(GmailApiTransport::class, $mailer->getSymfonyTransport());
        $this->assertSame('smtp', config('mail.mailers.smtp.transport'));
        $this->assertNotSame('gmail_api', config('mail.default'));
    }

    public function test_missing_oauth_configuration_fails_with_a_sanitized_message(): void
    {
        try {
            new GoogleGmailApiSender(null, 'test-client-secret', 'test-refresh-token');
            $this->fail('Missing OAuth configuration was accepted.');
        } catch (GmailApiTransportException $exception) {
            $this->assertSame('The Gmail API mail transport is not configured.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_message_is_mime_encoded_and_sent_to_the_gmail_api_using_mock_http(): void
    {
        [$transport, $history] = $this->transportWithMockHttp([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'fake-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"mock-message-id"}'),
        ]);

        $email = (new Email)
            ->from(new Address('sender@example.test', 'eReserve'))
            ->to(new Address('resident@example.test'))
            ->subject('Test subject')
            ->text('Plain text body')
            ->html('<p>HTML body</p>')
            ->attach('attachment bytes', 'receipt.txt', 'text/plain');

        $transport->send($email, Envelope::create($email));

        $requests = iterator_to_array($history);
        $this->assertCount(2, $requests);
        $this->assertSame('oauth2.googleapis.com', $requests[0]['request']->getUri()->getHost());
        $this->assertSame('/token', $requests[0]['request']->getUri()->getPath());
        $this->assertSame('gmail.googleapis.com', $requests[1]['request']->getUri()->getHost());
        $this->assertStringEndsWith('/gmail/v1/users/sender%40example.test/messages/send', (string) $requests[1]['request']->getUri());

        $request = json_decode((string) $requests[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('raw', $request);
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $request['raw']);
        $mime = base64_decode(strtr($request['raw'], '-_', '+/'), true);
        $this->assertIsString($mime);
        $this->assertStringContainsString('To: resident@example.test', $mime);
        $this->assertStringContainsString('Subject: Test subject', $mime);
        $this->assertStringContainsString('Plain text body', $mime);
        $this->assertStringContainsString('HTML body', $mime);
        $this->assertStringContainsString('filename=receipt.txt', $mime);
        $this->assertStringContainsString(base64_encode('attachment bytes'), $mime);
    }

    public function test_google_api_failure_is_wrapped_without_provider_details_or_exception_chain(): void
    {
        [$transport] = $this->transportWithMockHttp([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'fake-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            new Response(403, ['Content-Type' => 'application/json'], '{"error":{"message":"private provider detail"}}'),
        ]);
        $email = (new Email)->from('sender@example.test')->to('resident@example.test')->subject('Test')->text('Body');

        try {
            $transport->send($email, Envelope::create($email));
            $this->fail('The Gmail API failure was not surfaced.');
        } catch (GmailApiTransportException $exception) {
            $this->assertSame('Gmail API email delivery failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('private provider detail', (string) $exception);
        }
    }

    public function test_two_factor_service_accepts_gmail_api_without_sending_a_real_email(): void
    {
        config([
            'mail.default' => 'gmail_api',
            'mail.mailers.gmail_api.transport' => 'gmail_api',
        ]);
        $user = User::factory()->create();

        app(TwoFactorCodes::class)->issue($user, 'setup', 'test-session-binding');

        Notification::assertSentTo($user, SecurityCode::class);
        $this->assertDatabaseHas('two_factor_challenges', ['user_id' => $user->id, 'purpose' => 'setup']);
    }

    public function test_password_reset_controller_accepts_gmail_api_without_sending_a_real_email(): void
    {
        config([
            'mail.default' => 'gmail_api',
            'mail.mailers.gmail_api.transport' => 'gmail_api',
        ]);
        User::factory()->create(['email' => 'resident@example.test']);

        $this->post(route('password.email'), ['email' => 'resident@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', \App\Http\Controllers\PasswordResetController::SENT_MESSAGE);
        Notification::assertSentTo(User::where('email', 'resident@example.test')->firstOrFail(), \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    private function transportWithMockHttp(array $responses): array
    {
        $history = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $http = new HttpClient(['handler' => $stack]);
        $googleClient = new GoogleClient;
        $googleClient->setHttpClient($http);

        return [
            new GmailApiTransport(
                new GoogleGmailApiSender('test-client-id', 'test-client-secret', 'test-refresh-token', $googleClient),
                'sender@example.test',
            ),
            $history,
        ];
    }
}
