<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
    }

    public function test_registration_sends_verification_and_authenticates_an_unverified_user(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Resident', 'email' => 'resident@example.com', 'barangay' => 'Washington',
            'phone_number' => '09171234567',
            'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'super_admin',
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'resident@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame('user', $user->role);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_roles_can_login_but_cannot_access_application_pages(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect(route('verification.notice'));
            $this->get(route('verification.notice'))->assertOk()->assertSee($user->email);
            foreach (['dashboard', 'facilities', 'reservations.index', 'admins.create', 'profile.edit'] as $route) {
                $this->get(route($route))->assertRedirect(route('verification.notice'));
            }
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_actual_notification_link_verifies_user_and_is_idempotent(): void
    {
        $user = User::factory()->unverified()->create();
        $url = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->actingAs($user)->get($url)->assertOk()
            ->assertSee('Email verified successfully!')
            ->assertSee('Redirecting you to eReserve...')
            ->assertSee('Continue to eReserve')
            ->assertViewHas('destination', route('dashboard'));
        $verifiedAt = $user->fresh()->email_verified_at;
        $this->assertNotNull($verifiedAt);
        $this->get($url)->assertOk()->assertSee('Your email is already verified.');
        $this->assertTrue($verifiedAt->equalTo($user->fresh()->email_verified_at));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_invalid_expired_and_other_users_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->actingAs($user)->get($url.'&tampered=1')->assertForbidden();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->get($expired)->assertForbidden();
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->get($url)->assertForbidden()
            ->assertSee('This verification link belongs to a different account.');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_resend_sends_notification_and_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->from(route('verification.notice'))->post(route('verification.send'))
                ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'verification-link-sent');
        }
        $this->post(route('verification.send'))->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    }

    public function test_verified_roles_keep_their_existing_dashboard_and_permissions(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect(route('dashboard'));
            $this->get(route('dashboard'))->assertOk();
            $this->get(route('admins.create'))->assertStatus($role === 'super_admin' ? 200 : 403);
            $this->get(route('verification.notice'))->assertRedirect(route('dashboard'));
            $this->post(route('verification.send'))->assertRedirect(route('dashboard'));
            $this->post(route('logout'));
        }
        Notification::assertNothingSent();
    }

    public function test_admin_creation_sends_verification_without_changing_super_admin_session(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($superAdmin)->post(route('admins.store'), [
            'name' => 'Admin', 'email' => 'new-admin@example.com', 'barangay' => 'Washington',
            'phone_number' => '09171234567',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect();
        $admin = User::where('email', 'new-admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertFalse($admin->hasVerifiedEmail());
        $this->assertAuthenticatedAs($superAdmin);
        Notification::assertSentTo($admin, VerifyEmail::class);
    }

    public function test_changing_email_requires_verification_and_invalidates_old_link(): void
    {
        $user = User::factory()->create();
        $oldUrl = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'changed@example.com'])
            ->assertRedirect(route('verification.notice'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->get($oldUrl)->assertForbidden()
            ->assertSee('This link does not match your current email address.');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_name_change_preserves_verification(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_smtp_failure_keeps_registration_recoverable(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('SMTP unavailable'));
        $this->post(route('register.store'), [
            'name' => 'Resident', 'email' => 'resident@example.com', 'barangay' => 'Washington',
            'phone_number' => '09171234567',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('verification.notice'))->assertSessionHasErrors('verification');
        $this->assertAuthenticated();
        $this->get(route('verification.notice'))->assertOk();
    }

    public function test_guests_cannot_access_verification_endpoints(): void
    {
        $this->get(route('verification.notice'))->assertRedirect(route('login'));
        $this->post(route('verification.send'))->assertRedirect(route('login'));
        $this->postJson(route('verification.status'))->assertRedirect(route('login'));
        $user = User::factory()->unverified()->create();
        $this->get((new VerifyEmail)->toMail($user)->actionUrl)->assertRedirect(route('login'));
    }

    public function test_status_reads_current_database_state_and_returns_only_a_boolean(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->postJson(route('verification.status'))
            ->assertOk()->assertExactJson(['verified' => false])
            ->assertHeader('Cache-Control', 'no-store, private');

        // Another tab verifies the account; this request still holds the older model instance.
        $user->fresh()->markEmailAsVerified();
        $this->postJson(route('verification.status'))->assertOk()->assertExactJson(['verified' => true]);
    }

    public function test_success_and_original_tab_preserve_existing_intended_destination_for_all_roles(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role]);
            $destination = route('reservations.index');
            $this->actingAs($user)->withSession(['url.intended' => $destination])
                ->get(route('verification.notice'))->assertOk()->assertViewHas('destination', $destination);
            $this->get((new VerifyEmail)->toMail($user)->actionUrl)
                ->assertOk()->assertViewHas('destination', $destination);
            $this->get($destination)->assertOk();
            $this->assertAuthenticatedAs($user);
        }
    }

    public function test_deleted_recipient_link_explains_failure_without_verifying_current_account(): void
    {
        $recipient = User::factory()->unverified()->create();
        $url = (new VerifyEmail)->toMail($recipient)->actionUrl;
        $recipient->delete();
        $current = User::factory()->unverified()->create();

        $this->actingAs($current)->get($url)->assertForbidden()
            ->assertSee('This verification link belongs to an account that no longer exists.')
            ->assertSee('Continue with current account')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse($current->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($current);
    }

    public function test_guest_can_login_and_resume_the_signed_link_without_a_redirect_loop(): void
    {
        $user = User::factory()->unverified()->create();
        $url = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
        $this->get(route('login'))->assertSee('Your verification link will continue after login.');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($url)->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
        $this->get($url)->assertOk()->assertSee('Email verified successfully!')
            ->assertViewHas('destination', route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_guest_logging_in_to_wrong_account_cannot_resume_another_accounts_verification(): void
    {
        $recipient = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $url = (new VerifyEmail)->toMail($recipient)->actionUrl;
        $this->get($url)->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $other->email, 'password' => 'password'])
            ->assertRedirect($url);
        $this->get($url)->assertForbidden()->assertSee('This verification link belongs to a different account.');
        $this->assertFalse($recipient->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_signature_and_expiry_are_still_enforced_after_guest_login(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->get($url)->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($url);
        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_registration_session_can_verify_notification_link_and_original_tab_reads_verified_state(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Session Resident', 'email' => 'session@example.com', 'barangay' => 'Washington',
            'phone_number' => '09171234567',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'session@example.com')->firstOrFail();
        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });
        $this->app['auth']->forgetGuards();
        $this->get(route('verification.notice'))->assertOk();
        $this->postJson(route('verification.status'))->assertExactJson(['verified' => false]);
        $this->get($url)->assertOk()->assertSee('Email verified successfully!');
        $this->postJson(route('verification.status'))->assertExactJson(['verified' => true]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_resent_notification_link_verifies_the_authenticated_recipient(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user) {
            $this->get($notification->toMail($user)->actionUrl)->assertOk()->assertSee('Email verified successfully!');

            return true;
        });
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}
