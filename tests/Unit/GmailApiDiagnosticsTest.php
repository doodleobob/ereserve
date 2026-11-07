<?php

namespace Tests\Unit;

use App\Http\Controllers\PasswordResetController;
use App\Mail\Transports\GmailApiFailureLogger;
use App\Mail\Transports\GmailApiSender;
use App\Mail\Transports\GmailApiTransport;
use App\Mail\Transports\GmailApiTransportException;
use App\Mail\Transports\GoogleGmailApiSender;
use App\Models\User;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Throwable;

class GmailApiDiagnosticsTest extends TestCase
{
    public function test_oauth_refresh_failure_is_classified_and_retains_original_exception(): void
    {
        $sender = $this->senderWithResponses([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'private-refresh-token-value',
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $sender->send('sender@example.test', 'encoded-message');
            $this->fail('The failed OAuth refresh was not reported.');
        } catch (GmailApiTransportException $exception) {
            $this->assertSame('oauth_refresh', $exception->diagnosticContext()['operation']);
            $this->assertSame('authentication', $exception->diagnosticContext()['error_category']);
            $this->assertNotNull($exception->getPrevious());
            $this->assertStringNotContainsString('private-refresh-token-value', $exception->getMessage());
        }
    }

    public function test_gmail_send_failure_is_classified_and_retains_original_exception(): void
    {
        $sender = $this->senderWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'fake-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            new Response(403, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['message' => 'private-provider-response-body'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $sender->send('sender@example.test', 'encoded-message');
            $this->fail('The failed Gmail send was not reported.');
        } catch (GmailApiTransportException $exception) {
            $this->assertSame('gmail_send', $exception->diagnosticContext()['operation']);
            $this->assertSame('authorization', $exception->diagnosticContext()['error_category']);
            $this->assertNotNull($exception->getPrevious());
            $this->assertStringNotContainsString('private-provider-response-body', $exception->getMessage());
        }
    }

    public function test_diagnostic_logs_contain_only_allowlisted_context(): void
    {
        Log::spy();
        $exceptions = [
            GmailApiTransportException::failure(
                'oauth_refresh', 'authentication',
                new RuntimeException('private-client-secret private-refresh-token resident@example.test MIME body'),
            ),
            GmailApiTransportException::failure(
                'gmail_send', 'authorization',
                new RuntimeException('private-client-secret private-refresh-token resident@example.test MIME body'),
            ),
        ];

        foreach ($exceptions as $exception) {
            foreach (['setup', 'login', 'verify', 'password_reset'] as $index => $purpose) {
                GmailApiFailureLogger::log($exception, $purpose, sprintf('00000000-0000-4000-8000-%012d', $index + 1));
            }
        }

        Log::shouldHaveReceived('warning')->times(8)->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Gmail API delivery failed.', $message);
            $this->assertSame(
                ['operation', 'exception_class', 'error_category', 'purpose', 'correlation_id'],
                array_keys($context),
            );
            $this->assertContains($context['operation'], ['oauth_refresh', 'gmail_send']);
            $this->assertSame(RuntimeException::class, $context['exception_class']);
            $this->assertContains($context['purpose'], ['setup', 'login', 'verify', 'password_reset']);
            $this->assertDoesNotMatchRegularExpression(
                '/private-client-secret|private-refresh-token|resident@example\.test|MIME body/',
                json_encode($context, JSON_THROW_ON_ERROR),
            );

            return true;
        });
    }

    public function test_safe_error_classification_distinguishes_network_api_and_internal_failures(): void
    {
        $networkFailure = new ConnectException('private connection detail', new Request('POST', 'https://oauth2.example.test'));

        $this->assertSame('network', GmailApiTransportException::categoryFor($networkFailure, 'oauth_refresh'));
        $this->assertSame('api_rejection', GmailApiTransportException::categoryFor(
            new RuntimeException('private provider message', 429), 'gmail_send',
        ));
        $this->assertSame('internal', GmailApiTransportException::categoryFor(
            new RuntimeException('private internal message'), 'gmail_send',
        ));
    }

    public function test_transport_preserves_typed_failure_and_successful_mime_send(): void
    {
        $failure = GmailApiTransportException::failure(
            'gmail_send', 'api_rejection', new RuntimeException('private-provider-detail'),
        );
        $failingSender = new class($failure) implements GmailApiSender
        {
            public function __construct(private readonly Throwable $failure) {}

            public function send(string $user, string $base64UrlMessage): void
            {
                throw $this->failure;
            }
        };

        $email = (new Email)->from('sender@example.test')->to('resident@example.test')->subject('Subject')->text('Body');
        try {
            (new GmailApiTransport($failingSender, 'sender@example.test'))->send($email, Envelope::create($email));
            $this->fail('The typed transport failure was not surfaced.');
        } catch (GmailApiTransportException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame('Gmail API email delivery failed.', $exception->getMessage());
        }

        $sender = $this->senderWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'fake-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"mock-message-id"}'),
        ]);
        $successEmail = (new Email)->from('sender@example.test')->to('resident@example.test')->subject('Subject')->text('Body');

        (new GmailApiTransport($sender, 'sender@example.test'))->send($successEmail, Envelope::create($successEmail));
    }

    public function test_password_reset_failure_keeps_generic_response_and_logs_safe_gmail_context(): void
    {
        config([
            'mail.default' => 'gmail_api',
            'mail.mailers.gmail_api.transport' => 'gmail_api',
        ]);

        $failure = GmailApiTransportException::failure(
            'gmail_send', 'authentication', new RuntimeException('private-refresh-token'),
        );
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 42, 'email' => 'resident@example.test']);
        $user->shouldReceive('sendPasswordResetNotification')->once()->andThrow($failure);

        Password::shouldReceive('sendResetLink')->once()->andReturnUsing(
            function (array $credentials, callable $callback) use ($user): string {
                $callback($user, 'private-reset-token');

                return 'sent';
            },
        );
        Password::shouldReceive('deleteToken')->once()->with($user);
        Log::spy();
        $this->post(route('password.email'), ['email' => 'resident@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Gmail API delivery failed.', $message);
            $this->assertSame('gmail_send', $context['operation']);
            $this->assertSame('password_reset', $context['purpose']);
            $this->assertArrayHasKey('correlation_id', $context);
            $this->assertDoesNotMatchRegularExpression(
                '/private-refresh-token|private-reset-token|resident@example\.test/',
                json_encode($context, JSON_THROW_ON_ERROR),
            );

            return true;
        });
    }

    private function senderWithResponses(array $responses): GoogleGmailApiSender
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $client = new GoogleClient;
        $client->setHttpClient(new HttpClient(['handler' => $stack]));

        return new GoogleGmailApiSender('test-client-id', 'test-client-secret', 'test-refresh-token', $client);
    }
}
