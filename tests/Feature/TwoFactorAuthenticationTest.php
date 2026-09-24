<?php

namespace Tests\Feature;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\SecurityCode;
use App\Services\TwoFactorCodes;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
    }

    private function enabledUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->two_factor_method = 'email';
        $user->save();

        return $user;
    }

    private function latestCode(User $user): string
    {
        return Notification::sent($user, SecurityCode::class)->last()->toMail($user)->viewData['code'];
    }

    private function passwordLogin(User $user)
    {
        return $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    }

    public function test_setup_requires_password_and_code_before_enabling_and_masks_email(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('Disabled')->assertDontSee('Phone')->assertDontSee('SMS')->assertDontSee('+63')->assertDontSee('name="method"', false)
            ->assertSee($user->maskedTwoFactorDestination());
        $this->post(route('two-factor.setup'), ['current_password' => 'incorrect'])
            ->assertSessionHasErrors('current_password', null, 'security');
        Notification::assertNothingSent();
        $this->post(route('two-factor.setup'), ['current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->twoFactorEnabled());
        $code = $this->latestCode($user);
        $record = TwoFactorChallenge::firstOrFail();
        $this->assertTrue((bool) preg_match('/^[0-9]{6}$/', $code));
        $this->assertTrue(Hash::check($code, $record->code_hash));
        $this->assertTrue($code !== $record->code_hash);
        $this->assertTrue(DB::table('two_factor_challenges')->value('destination') !== $user->email);
        $this->assertFalse(str_contains(json_encode($record), $record->code_hash));
        $this->post(route('two-factor.confirm'), ['code' => $code])->assertRedirect(route('profile.edit').'#security');
        $this->assertSame('email', $user->fresh()->two_factor_method);
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_pending_login_is_guest_and_cannot_bypass_challenge_for_any_role(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = $this->enabledUser(['role' => $role]);
            $this->passwordLogin($user)->assertRedirect(route('two-factor.challenge'));
            $this->assertGuest();
            $this->get(route('two-factor.challenge'))->assertOk()->assertDontSee($user->email);
            foreach (['dashboard', 'reservations.index', 'facilities', 'admins.create', 'settings.security', 'verification.notice'] as $route) {
                $this->get(route($route))->assertRedirect(route('login'));
            }
            $this->post(route('two-factor.disable'), ['_method' => 'DELETE', 'current_password' => 'password'])->assertRedirect(route('login'));
            $this->withSession(['url.intended' => route('reservations.index')]);
            $this->post(route('two-factor.verify'), ['code' => $this->latestCode($user)])
                ->assertRedirect(route('reservations.index'))->assertSessionMissing('two_factor.login');
            $this->assertAuthenticatedAs($user);
            $this->get(route('reservations.index'))->assertOk();
            $this->get(route('admins.create'))->assertStatus($role === 'super_admin' ? 200 : 403);
            $this->post(route('logout'));
        }
    }

    public function test_wrong_password_never_sends_a_code(): void
    {
        $user = $this->enabledUser();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrect'])
            ->assertSessionHasErrors('email')->assertSessionMissing('two_factor.login');
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_wrong_codes_lock_challenge_after_five_guesses_and_cannot_be_replayed(): void
    {
        $user = $this->enabledUser();
        $this->passwordLogin($user);
        $code = $this->latestCode($user);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_code_expires_at_five_minutes_and_pending_login_at_ten(): void
    {
        $user = $this->enabledUser();
        $this->passwordLogin($user);
        $code = $this->latestCode($user);
        $this->travel(301)->seconds();
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->travel(300)->seconds();
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_resend_is_limited_and_invalidates_the_previous_code(): void
    {
        $user = $this->enabledUser();
        $this->passwordLogin($user);
        $oldId = TwoFactorChallenge::firstOrFail()->id;
        $oldCode = $this->latestCode($user);
        $this->post(route('two-factor.resend'))->assertSessionHasErrors('two_factor');
        Notification::assertSentToTimes($user, SecurityCode::class, 1);
        $this->travel(61)->seconds();
        $this->post(route('two-factor.resend'))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('two_factor_challenges', ['id' => $oldId]);
        $this->assertDatabaseCount('two_factor_challenges', 1);
        $this->post(route('two-factor.verify'), ['code' => $oldCode])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => $this->latestCode($user)])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_code_is_bound_to_its_browser_session_and_is_single_use(): void
    {
        $user = $this->enabledUser();
        $this->passwordLogin($user);
        $pending = session('two_factor.login');
        $code = $this->latestCode($user);
        $service = app(TwoFactorCodes::class);
        $this->assertNull($service->consume($user->id, 'login', 'another-session', $code));
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertNull($service->consume($user->id, 'login', $pending['binding'], $code));
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_password_or_destination_changes_invalidate_pending_login(): void
    {
        $user = $this->enabledUser();
        $this->passwordLogin($user);
        $code = $this->latestCode($user);
        $user->update(['password' => 'replacement-password']);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_disable_requires_password_and_clears_pending_codes(): void
    {
        $user = $this->enabledUser();
        $this->actingAs($user)->delete(route('two-factor.disable'), ['current_password' => 'incorrect'])
            ->assertSessionHasErrors('current_password', null, 'security');
        $this->assertTrue($user->fresh()->twoFactorEnabled());
        $this->delete(route('two-factor.disable'), ['current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->twoFactorEnabled());
        $this->post(route('logout'));
        $this->passwordLogin($user)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_accounts_cannot_access_settings_or_enable_two_factor(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('verification.notice'));
        $this->post(route('two-factor.setup'), ['current_password' => 'password'])
            ->assertRedirect(route('verification.notice'));
        Notification::assertNothingSent();
    }

    public function test_validation_does_not_flash_codes_and_delivery_failure_never_authenticates(): void
    {
        $user = $this->enabledUser();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Simulated provider failure'));
        $this->passwordLogin($user)->assertRedirect(route('two-factor.challenge'))->assertSessionHasErrors('two_factor');
        $this->assertGuest();
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->post(route('two-factor.verify'), ['code' => 'invalid'])->assertSessionHasErrors('code');
        $this->assertNull(session('_old_input.code'));
    }

    public function test_otp_completes_before_continuing_email_verification_link(): void
    {
        $user = $this->enabledUser();
        $url = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->get($url)->assertRedirect(route('login'));
        $this->passwordLogin($user)->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => $this->latestCode($user)])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Your email is already verified.');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_disable_is_required_before_changing_the_verified_two_factor_email(): void
    {
        $user = $this->enabledUser();
        $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])
            ->assertSessionHasErrors('email');
        $this->assertTrue($user->fresh()->email !== 'new@example.com');
        $this->delete(route('two-factor.disable'), ['current_password' => 'password']);
        $this->actingAs($user->fresh())->patch(route('profile.update'), [
            'name' => $user->name, 'email' => 'new@example.com',
        ])->assertRedirect(route('verification.notice'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_notification_renders_and_is_submitted_using_laravels_mail_transport(): void
    {
        // Exercise the real mail channel without delivering test credentials to an inbox.
        Notification::swap(new ChannelManager($this->app));
        $submitted = false;
        Event::listen(MessageSent::class, function ($event) use (&$submitted) {
            $mail = $event->sent->getOriginalMessage();
            $submitted = $mail->getSubject() === 'Your eReserve security code'
                && str_contains($mail->getHtmlBody(), 'This code expires in 5 minutes');
        });
        $this->passwordLogin($this->enabledUser())->assertRedirect(route('two-factor.challenge'));
        $this->assertTrue($submitted);
        $this->assertGuest();
    }

    public function test_log_mailers_are_rejected_without_sending_or_exposing_codes(): void
    {
        config(['mail.default' => 'log']);
        $this->passwordLogin($this->enabledUser())->assertRedirect(route('two-factor.challenge'))->assertSessionHasErrors('two_factor');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->assertGuest();
    }

    public function test_send_limits_and_guess_limits_follow_account_across_sessions(): void
    {
        $user = $this->enabledUser();
        RateLimiter::hit('otp-send-window:'.$user->id, 900);
        RateLimiter::hit('otp-send-window:'.$user->id, 900);
        RateLimiter::hit('otp-send-window:'.$user->id, 900);
        RateLimiter::hit('otp-send-window:'.$user->id, 900);
        RateLimiter::hit('otp-send-window:'.$user->id, 900);
        $this->passwordLogin($user)->assertSessionHasErrors('two_factor');
        Notification::assertNothingSent();
        RateLimiter::clear('otp-send-window:'.$user->id);
        $this->passwordLogin($user);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('otp-guesses:'.$user->id, 300);
        }
        $this->post(route('two-factor.verify'), ['code' => $this->latestCode($user)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_pending_challenge_survives_guard_reload_but_cancel_invalidates_it(): void
    {
        $user = $this->enabledUser();
        $this->passwordLogin($user);
        $this->app['auth']->forgetGuards();
        $this->get(route('two-factor.challenge'))->assertOk();
        $this->assertGuest();
        $this->post(route('two-factor.cancel'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_wrong_and_expired_setup_codes_never_enable_two_factor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('two-factor.setup'), ['current_password' => 'password']);
        $code = $this->latestCode($user);
        $this->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code', null, 'security');
        $this->assertFalse($user->fresh()->twoFactorEnabled());
        $this->travel(301)->seconds();
        $this->post(route('two-factor.confirm'), ['code' => $code])->assertSessionHasErrors('code', null, 'security');
        $this->assertFalse($user->fresh()->twoFactorEnabled());
        $this->post(route('two-factor.setup.cancel'))->assertRedirect(route('profile.edit').'#security')->assertSessionMissing('two_factor.setup');
    }

    public function test_request_parameters_cannot_select_another_user_or_destination(): void
    {
        $user = User::factory()->create();
        $other = $this->enabledUser();
        $this->actingAs($user)->post(route('two-factor.setup'), [
            'current_password' => 'password', 'user_id' => $other->id, 'email' => $other->email,
            'method' => 'phone', 'phone_number' => 'ignored',
        ])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, SecurityCode::class);
        Notification::assertNotSentTo($other, SecurityCode::class);
        $challenge = TwoFactorChallenge::firstOrFail();
        $this->assertSame($user->id, $challenge->user_id);
        $this->assertSame('email', $challenge->method);
        $this->assertTrue($challenge->destination === $user->email);
        $this->post(route('two-factor.confirm'), ['code' => $this->latestCode($user), 'user_id' => $other->id]);
        $this->actingAs($user->fresh())->delete(route('two-factor.disable'), ['current_password' => 'password', 'user_id' => $other->id]);
        $this->assertFalse($user->fresh()->twoFactorEnabled());
        $this->assertTrue($other->fresh()->twoFactorEnabled());
    }

    public function test_enabled_page_has_only_disable_and_cannot_restart_setup(): void
    {
        $user = $this->enabledUser();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee('Enabled')
            ->assertSee('Disable Two-Factor Authentication')->assertSee('Cancel')
            ->assertDontSee('Change Method')->assertDontSee('Phone')->assertDontSee('SMS')
            ->assertDontSee('type="radio"', false)->assertDontSee('name="phone_number"', false);
        $this->post(route('two-factor.setup'), ['current_password' => 'password'])->assertSessionHasNoErrors();
        Notification::assertNothingSent();
    }

    public function test_disabled_login_sends_no_code_and_disable_clears_pending_state(): void
    {
        $user = $this->enabledUser();
        $this->passwordLogin($user);
        $this->actingAs($user)->withSession(['two_factor.setup' => ['binding' => 'unused']])
            ->delete(route('two-factor.disable'), ['current_password' => 'password'])
            ->assertSessionMissing('two_factor.setup')->assertSessionMissing('two_factor.login');
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->post(route('logout'));
        Notification::fake();
        $this->passwordLogin($user->fresh())->assertRedirect(route('dashboard'));
        Notification::assertNothingSent();
        $this->assertAuthenticatedAs($user);
    }

    public function test_setup_resend_replaces_code_and_confirmation_is_single_use(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('two-factor.setup'), ['current_password' => 'password']);
        $oldCode = $this->latestCode($user);
        $this->post(route('two-factor.setup.resend'))->assertSessionHasErrors('two_factor', null, 'security');
        $this->travel(61)->seconds();
        $this->post(route('two-factor.setup.resend'))->assertSessionHasNoErrors();
        $this->post(route('two-factor.confirm'), ['code' => $oldCode])->assertSessionHasErrors('code', null, 'security');
        $code = $this->latestCode($user);
        $this->post(route('two-factor.confirm'), ['code' => $code])->assertRedirect(route('profile.edit').'#security');
        $this->post(route('two-factor.confirm'), ['code' => $code])->assertSessionHasErrors('code', null, 'security');
        $this->assertTrue($user->fresh()->twoFactorEnabled());
    }
}
