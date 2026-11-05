<?php

namespace Tests\Feature;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\SecurityCode;
use App\Services\TwoFactorCodes;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge(['name' => 'Resident', 'username' => 'resident', 'email' => 'resident@example.com', 'barangay' => 'Washington',
            'phone_number' => '09171234567', 'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'super_admin'], $overrides);
    }

    private function code(User $user): string
    {
        return Notification::sent($user, SecurityCode::class)->last()->toMail($user)->viewData['code'];
    }

    public function test_registration_creates_unverified_resident_without_sending_email_or_logging_in(): void
    {
        $this->post(route('register.store'), $this->registration())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'registration-success');
        $user = User::where('email', 'resident@example.com')->firstOrFail();
        $this->assertGuest();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame('user', $user->role);
        Notification::assertNothingSent();
        Notification::assertNotSentTo($user, VerifyEmail::class);
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->get(route('login'))->assertOk()
            ->assertSee('Registration Successful!')
            ->assertSee('Your account has been created successfully. You can now log in using your registered username or email address and password.');
        $this->post(route('login.store'), ['email' => $user->username, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_registration_requires_a_valid_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        foreach (['taken@example.com', 'not-an-email'] as $email) {
            $this->from(route('register'))->post(route('register.store'), $this->registration(['email' => $email]))
                ->assertRedirect(route('register'))->assertSessionHasErrors('email');
        }
        $this->assertDatabaseCount('users', 1);
        Notification::assertNothingSent();
    }

    public function test_incorrect_code_fails_correct_code_verifies_once_and_allows_normal_login(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $code = $this->code($user);
        $this->actingAs($user)->post(route('verification.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->post(route('verification.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $timestamp = $user->fresh()->email_verified_at;
        $this->assertNotNull($timestamp);
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->assertNull(app(TwoFactorCodes::class)->consume($user->id, 'verify', app(TwoFactorCodes::class)->context($user), $code));
        $this->post(route('verification.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertTrue($timestamp->equalTo($user->fresh()->email_verified_at));
        Event::assertDispatchedTimes(Verified::class, 1);
        $this->get(route('dashboard'))->assertOk();
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    }

    public function test_codes_are_scoped_to_the_authenticated_user_and_expire(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $owner->sendEmailVerificationNotification();
        $code = $this->code($owner);
        $this->actingAs($other)->post(route('verification.verify'), ['code' => $code, 'user_id' => $owner->id])->assertSessionHasErrors('code');
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
        $this->travel(6)->minutes();
        $this->actingAs($owner)->post(route('verification.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_five_incorrect_guesses_invalidate_the_challenge_and_codes_are_not_flashed(): void
    {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $code = $this->code($user);
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('verification.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->assertDatabaseCount('two_factor_challenges', 0);
        $this->post(route('verification.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->post(route('verification.verify'), ['code' => 'invalid'])->assertSessionHasErrors('code');
        $this->assertNull(session('_old_input.code'));
    }

    public function test_resend_replaces_the_code_and_preserves_send_limits(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status', 'verification-code-sent');
        $old = $this->code($user);
        $this->post(route('verification.send'))->assertSessionHasErrors('verification');
        Notification::assertCount(1);
        $this->travel(61)->seconds();
        $this->post(route('verification.send'))->assertSessionHas('status', 'verification-code-sent');
        $new = $this->code($user);
        $this->assertNotSame($old, $new);
        $this->assertDatabaseCount('two_factor_challenges', 1);
        $this->post(route('verification.verify'), ['code' => $old])->assertSessionHasErrors('code');
        $this->post(route('verification.verify'), ['code' => $new])->assertRedirect(route('dashboard'));
        Notification::assertCount(2);
    }

    public function test_unverified_accounts_of_all_roles_can_log_in_and_access_permitted_pages(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            foreach (['dashboard', 'facilities', 'reservations.index', 'profile.edit'] as $route) {
                $this->get(route($route))->assertOk();
            }
            $this->get(route('admins.create'))->assertStatus($role === 'super_admin' ? 200 : 403);
            $this->post(route('logout'));
        }
    }

    public function test_verified_roles_keep_existing_permissions_and_receive_no_verification_email(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
            $this->get(route('dashboard'))->assertOk();
            $this->get(route('admins.create'))->assertStatus($role === 'super_admin' ? 200 : 403);
            $this->get(route('verification.notice'))->assertRedirect(route('dashboard'));
            $this->post(route('verification.send'))->assertRedirect(route('dashboard'));
            $user->sendEmailVerificationNotification();
            $this->post(route('logout'));
        }
        Notification::assertNothingSent();
    }

    public function test_admin_creation_needs_no_email_verification_and_keeps_super_admin_session(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->post(route('admins.store'), $this->registration())->assertSessionHasNoErrors();
        $admin = User::where('email', 'resident@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertNull($admin->email_verified_at);
        $this->assertAuthenticatedAs($super);
        Notification::assertNothingSent();
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_changing_email_requires_a_code_for_the_new_address(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'changed@example.com'])->assertRedirect(route('verification.notice'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame('changed@example.com', TwoFactorChallenge::sole()->destination);
        Notification::assertCount(1);
        $code = $this->code($user);
        $user->forceFill(['email' => 'another@example.com'])->save();
        $this->post(route('verification.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_name_change_preserves_verification(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_registration_does_not_attempt_delivery_even_when_mail_is_unavailable(): void
    {
        config(['mail.default' => 'log']);
        $this->post(route('register.store'), $this->registration())->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('two_factor_challenges', 0);
        Notification::assertNothingSent();
    }

    public function test_log_mailer_cannot_expose_verification_codes(): void
    {
        config(['mail.default' => 'log']);
        $this->post(route('register.store'), $this->registration())->assertRedirect(route('login'));
        Notification::assertNothingSent();
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_guests_cannot_verify_and_signed_links_and_polling_route_no_longer_exist(): void
    {
        $this->get(route('verification.notice'))->assertRedirect(route('login'));
        $this->post(route('verification.verify'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->post(route('verification.send'))->assertRedirect(route('login'));
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/email/verify/'.$user->id.'/'.sha1($user->email).'?signature=obsolete')->assertNotFound();
        $this->post('/email/verification-status')->assertNotFound();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('verification.verify')->methods());
        $this->assertCount(3, collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'verification.')));
    }

    public function test_verification_preserves_intended_destination_for_all_roles(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role]);
            $user->sendEmailVerificationNotification();
            $this->actingAs($user)->withSession(['url.intended' => route('reservations.index')])
                ->post(route('verification.verify'), ['code' => $this->code($user)])->assertRedirect(route('reservations.index'));
            $this->get(route('reservations.index'))->assertOk();
        }
    }

    public function test_registration_sends_no_email(): void
    {
        Notification::swap(new ChannelManager($this->app));
        $messages = [];
        Event::listen(MessageSent::class, function ($event) use (&$messages) {
            $messages[] = $event->sent->getOriginalMessage();
        });
        $this->post(route('register.store'), $this->registration())->assertRedirect(route('login'));
        $this->assertCount(0, $messages);
    }
}
