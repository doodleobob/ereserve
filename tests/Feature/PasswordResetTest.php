<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordResetController;
use App\Models\User;
use App\Notifications\SecurityCode;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public static function roles(): array
    {
        return [['user'], ['admin'], ['super_admin']];
    }

    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];
    }

    #[DataProvider('roles')]
    public function test_registered_accounts_receive_a_hashed_token_and_neutral_response(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordResetController::SENT_MESSAGE)
            ->assertSessionHasNoErrors();
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertNotSentTo($user, SecurityCode::class);
        Notification::assertNotSentTo($user, VerifyEmail::class);
        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotSame($notification->token, $stored->token);
        $this->assertTrue(Hash::check($notification->token, $stored->token));
        $this->assertTrue(Password::tokenExists($user, $notification->token));
        $this->get(route('password.request'))->assertOk()->assertSee(PasswordResetController::SENT_MESSAGE)
            ->assertDontSee($notification->token)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
    }

    public function test_unknown_and_throttled_accounts_receive_the_same_neutral_response(): void
    {
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            $this->post(route('password.email'), ['email' => $email])
                ->assertRedirect(route('password.request'))->assertSessionHasNoErrors()
                ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        }
        Notification::assertCount(1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    #[DataProvider('roles')]
    public function test_reset_is_single_use_preserves_account_and_allows_only_new_password(string $role): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['role' => $role, 'remember_token' => 'old-remember-token']);
        $before = $user->only(['name', 'email', 'phone_number', 'role', 'barangay', 'email_verified_at', 'is_active', 'two_factor_method']);
        $token = Password::createToken($user);
        $data = $this->resetData($user, $token) + ['role' => 'super_admin', 'barangay' => 'Taft'];
        $this->post(route('password.update'), $data)->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your password has been reset successfully. You can now log in.');
        $this->assertGuest();
        $this->get(route('login'))->assertSee('Your password has been reset successfully. You can now log in.');
        $fresh = $user->fresh();
        $this->assertEquals($before, $fresh->only(array_keys($before)));
        $this->assertTrue(Hash::check('new-password-123', $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Event::assertDispatchedTimes(PasswordReset::class, 1);
        $this->post(route('password.update'), $data)->assertSessionHasErrors('reset');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'new-password-123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_wrong_and_cross_account_tokens_are_rejected_without_disclosing_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::createToken($user);
        $hash = $user->password;
        foreach ([$this->resetData($user, 'invalid-token'), $this->resetData($other, $token),
            array_replace($this->resetData($user, $token), ['email' => 'unknown@example.test'])] as $data) {
            $this->post(route('password.update'), $data)->assertSessionHasErrors([
                'reset' => 'This password reset link is invalid or has expired. Please request a new link.',
            ]);
        }
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->post(route('password.update'), $this->resetData($user, $token))->assertSessionHasErrors('reset');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_password_rules_match_registration_and_secrets_are_never_flashed(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
        foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['password_confirmation' => 'mismatch']] as $overrides) {
            $this->from($url)->post(route('password.update'), array_replace($this->resetData($user, $token), $overrides))
                ->assertRedirect($url)->assertSessionHasErrors('password')
                ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation')
                ->assertSessionMissing('_old_input.token');
        }
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_does_not_verify_email_or_enable_a_deactivated_account(): void
    {
        $user = User::factory()->unverified()->create(['is_active' => false]);
        $this->post(route('password.update'), $this->resetData($user, Password::createToken($user)))->assertRedirect(route('login'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($user->fresh()->is_active);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'new-password-123'])
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);
        $this->assertGuest();
    }

    public function test_reset_keeps_two_factor_enabled_and_requires_a_new_login_code(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_method' => 'email'])->save();
        $this->withSession(['two_factor.login' => ['user_id' => $user->id]])
            ->post(route('password.update'), $this->resetData($user, Password::createToken($user)))
            ->assertRedirect(route('login'))->assertSessionMissing('two_factor.login');
        $this->assertSame('email', $user->fresh()->two_factor_method);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'new-password-123'])
            ->assertRedirect(route('two-factor.challenge'));
        Notification::assertSentTo($user, SecurityCode::class);
        $this->assertGuest();
    }

    public function test_reset_request_does_not_modify_or_reuse_registration_codes(): void
    {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $code = Notification::sent($user, SecurityCode::class)->sole()->toMail($user)->viewData['code'];
        $challenge = DB::table('two_factor_challenges')->where('user_id', $user->id)->first();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        $this->assertEquals($challenge, DB::table('two_factor_challenges')->where('user_id', $user->id)->first());
        $this->post(route('password.update'), $this->resetData($user, $code))->assertSessionHasErrors('reset');
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;
        $this->actingAs($user)->post(route('verification.verify'), ['code' => $token])->assertSessionHasErrors('code');
        $this->post(route('verification.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_new_reset_link_replaces_old_token_after_the_configured_throttle(): void
    {
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email]);
        $first = Notification::sent($user, ResetPassword::class)->last()->token;
        $this->travel(config('auth.passwords.users.throttle') + 1)->seconds();
        $this->post(route('password.email'), ['email' => $user->email]);
        $second = Notification::sent($user, ResetPassword::class)->last()->token;
        $this->assertNotSame($first, $second);
        $this->assertFalse(Password::tokenExists($user, $first));
        $this->assertTrue(Password::tokenExists($user, $second));
    }

    public function test_delivery_failure_is_neutral_and_removes_unsent_token(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('private provider details'));
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasNoErrors()->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->get(route('password.request'))->assertDontSee('private provider details');
    }

    public function test_log_mailer_cannot_expose_reset_links_for_any_email(): void
    {
        config(['mail.default' => 'log']);
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $this->post(route('password.email'), ['email' => $email])->assertSessionHasErrors([
                'email' => 'Password reset emails are temporarily unavailable. Please try again later.',
            ]);
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_mail_uses_existing_transport_and_trusted_application_url(): void
    {
        Notification::swap(new ChannelManager($this->app));
        config(['app.url' => 'https://ereserve.example.test']);
        $messages = [];
        Event::listen(MessageSent::class, function ($event) use (&$messages) {
            $messages[] = $event->sent->getOriginalMessage();
        });
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        $this->assertCount(1, $messages);
        $this->assertSame($user->email, $messages[0]->getTo()[0]->getAddress());
        $html = $messages[0]->getHtmlBody();
        $this->assertStringContainsString('https://ereserve.example.test/reset-password/', $html);
        $this->assertStringContainsString(urlencode($user->email), $html);
        $this->assertStringContainsString('60 minutes', $html);
        $this->assertStringNotContainsString('/email/verify', $html);
        $this->assertStringNotContainsString($user->password, $html);
    }

    public function test_pages_have_shared_ui_no_store_and_reset_errors_have_a_recovery_link(): void
    {
        $this->get(route('login'))->assertSee('Forgot Password?')->assertSee(route('password.request'), false);
        foreach ([route('password.request'), route('password.reset', ['token' => 'test-token', 'email' => 'demo@example.test'])] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('Public Facility Reservation &amp; Resource Utilization Management System', false)
                ->assertSee('app-footer')->assertSee('Back to Login')->assertHeader('Cache-Control', 'no-store, private');
            if (str_contains($url, '/reset-password/')) {
                $response->assertSee('data-password-toggle', false)->assertHeader('Referrer-Policy', 'no-referrer');
            }
        }
        $url = route('password.reset', ['token' => 'bad-token', 'email' => 'demo@example.test']);
        $this->from($url)->post(route('password.update'), ['token' => 'bad-token', 'email' => 'demo@example.test',
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertRedirect($url);
        $this->get($url)->assertSee('This password reset link is invalid or has expired.')->assertSee('Request a new reset link');
    }

    public function test_request_endpoint_limits_abuse_and_validates_email(): void
    {
        $this->post(route('password.email'), ['email' => 'invalid'])->assertSessionHasErrors('email');
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('password.email'), ['email' => "unknown{$i}@example.test"])->assertRedirect(route('password.request'));
        }
        $this->post(route('password.email'), ['email' => 'another@example.test'])->assertStatus(429);
    }
}
