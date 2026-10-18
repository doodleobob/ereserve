<?php

namespace Tests\Feature;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\TwoFactorCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EmailVerificationDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public static function failures(): array
    {
        return [
            'authentication' => ['535 BadCredentials', RuntimeException::class, 'authentication'],
            'provider limit' => ['421 sending limit', RuntimeException::class, 'rate_limit'],
            'timeout' => ['Connection timed out', RuntimeException::class, 'timeout'],
            'connection' => ['Connection refused', RuntimeException::class, 'connection'],
            'recipient' => ['550 invalid address', RuntimeException::class, 'recipient'],
            'tls before connection' => ['Connection could not be established: SSL operation failed: certificate verify failed', RuntimeException::class, 'tls'],
            'starttls' => ['Unable to connect with STARTTLS', RuntimeException::class, 'tls'],
            'rendering' => ['Template rendering failed', ViewException::class, 'rendering'],
            'unknown' => ['Unexpected provider failure', RuntimeException::class, 'other'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failed_resend_records_only_safe_diagnostics(string $message, string $exceptionClass, string $category): void
    {
        Log::spy();
        $user = User::factory()->unverified()->create();
        $correlationId = null;
        $secrets = [];
        Notification::shouldReceive('send')->once()->andReturnUsing(function ($recipient, $notification) use ($message, $exceptionClass, &$correlationId, &$secrets) {
            $challenge = TwoFactorChallenge::sole();
            $correlationId = $challenge->id;
            $secrets = [
                $recipient->email,
                $notification->toMail($recipient)->viewData['code'],
                $recipient->password,
                $challenge->code_hash,
                $challenge->binding_hash,
                'private-smtp-password',
                'AUTH LOGIN private-transcript',
            ];
            throw new $exceptionClass($message.' '.implode(' ', $secrets), previous: new RuntimeException('private chained exception'));
        });

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHasErrors(['verification' => 'The security code could not be sent. Please try again shortly.'])
            ->assertSessionMissing('status');

        $this->assertTrue(Str::isUuid($correlationId));
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($exceptionClass, $category, $correlationId, $secrets) {
            $this->assertSame('Email verification notification failed.', $message);
            $this->assertSame([
                'stage' => 'notification_send',
                'exception_type' => $exceptionClass,
                'failure_category' => $category,
                'purpose' => 'verify',
                'correlation_id' => $correlationId,
            ], $context);
            $logged = json_encode([$message, $context]);
            foreach ([...$secrets, 'private chained exception', 'trace', 'exception_message'] as $secret) {
                // Exclude the independent UUID: its random digits could coincide with a code.
                $this->assertStringNotContainsString($secret, str_replace($correlationId, '', $logged));
            }

            return true;
        });
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);

        // A failed send still consumes the original rate-limit allowance.
        $this->post(route('verification.send'))
            ->assertSessionHasErrors(['verification' => 'Please wait before requesting another code.']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_unavailable_logger_preserves_cleanup_and_registration_error(): void
    {
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('Private log sink failure'));
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Private provider failure'));

        $this->post(route('register.store'), [
            'name' => 'Resident', 'username' => 'diagnosticresident', 'email' => 'diagnostic@example.com',
            'barangay' => 'Washington', 'phone_number' => '09171234567',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('verification.notice'))
            ->assertSessionHasErrors(['verification' => 'Your account was created, but the verification email could not be sent. Please try resending it.']);

        $this->assertAuthenticated();
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_successful_verification_send_does_not_log_a_failure(): void
    {
        Log::spy();
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-code-sent');

        Log::shouldNotHaveReceived('warning');
        $this->assertDatabaseCount('two_factor_challenges', 1);
    }

    public function test_two_factor_failures_do_not_emit_verification_diagnostics(): void
    {
        Log::spy();
        Notification::shouldReceive('send')->twice()->andThrow(new RuntimeException('Private provider failure'));

        foreach (['login', 'setup'] as $purpose) {
            $user = User::factory()->create();
            try {
                app(TwoFactorCodes::class)->issue($user, $purpose, 'test-binding');
                $this->fail('Expected the existing security-code error.');
            } catch (ValidationException $exception) {
                $this->assertSame(['two_factor' => ['The security code could not be sent. Please try again shortly.']], $exception->errors());
            }
        }

        Log::shouldNotHaveReceived('warning');
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }
}
