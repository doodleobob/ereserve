<?php

namespace Tests\Feature;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\SecurityCode;
use App\Support\Username;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernameAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Username Resident', 'username' => '  Juan.Dela_Cruz-1  ',
            'email' => 'resident@example.com', 'phone_number' => '09171234567',
            'barangay' => 'Washington', 'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    private function login(string $identifier, string $password = 'password')
    {
        return $this->from(route('login'))->post(route('login.store'), [
            'email' => $identifier, 'password' => $password,
        ]);
    }

    public static function roles(): array
    {
        return [['user'], ['admin'], ['super_admin']];
    }

    public function test_registration_normalizes_username_and_keeps_email_verification_and_role(): void
    {
        $this->post(route('register.store'), $this->registration(['role' => 'super_admin']))
            ->assertRedirect(route('verification.notice'))->assertSessionHasNoErrors();
        $user = User::where('email', 'resident@example.com')->sole();
        $this->assertSame('juan.dela_cruz-1', $user->username);
        $this->assertSame('user', $user->role);
        $this->assertSame('Washington', $user->barangay);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, SecurityCode::class);
        $this->assertSame($user->email, TwoFactorChallenge::sole()->destination);
    }

    public static function invalidUsernames(): array
    {
        return [[null], [''], ['  '], ['ab'], [str_repeat('a', 31)], ['resident@example.com'],
            ['resident@'], ['a b c'], ['.resident'], ['résident'], ['a/b'], [['resident']], [12345]];
    }

    #[DataProvider('invalidUsernames')]
    public function test_registration_rejects_invalid_usernames(mixed $username): void
    {
        $this->from(route('register'))->post(route('register.store'), $this->registration(['username' => $username]))
            ->assertRedirect(route('register'))->assertSessionHasErrors('username');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        Notification::assertNothingSent();
        $this->assertNull(session('_old_input.password'));
    }

    public function test_missing_username_is_required_at_registration(): void
    {
        $data = $this->registration();
        unset($data['username']);
        $this->post(route('register.store'), $data)
            ->assertSessionHasErrors(['username' => 'Please choose a username.']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_usernames_are_case_insensitive_and_global_across_barangays_and_roles(): void
    {
        $owner = User::factory()->create(['username' => 'Shared.Name', 'role' => 'admin', 'barangay' => 'Mabua']);
        $before = $owner->fresh()->getAttributes();
        $this->post(route('register.store'), $this->registration(['username' => ' SHARED.NAME ']))
            ->assertSessionHasErrors(['username' => Username::TAKEN_MESSAGE]);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertGuest();
    }

    public function test_database_unique_index_rejects_duplicates_without_request_validation(): void
    {
        User::factory()->create(['username' => 'shared.name']);
        $other = User::factory()->create(['barangay' => 'Mabua']);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->where('id', $other->id)->update(['username' => 'shared.name']);
    }

    public function test_registration_race_returns_a_username_error_instead_of_overwriting_an_account(): void
    {
        Event::listen('eloquent.creating: '.User::class, function (User $user) {
            DB::table('users')->insert([
                'name' => 'Concurrent Account', 'username' => $user->username,
                'email' => 'concurrent@example.com', 'password' => Hash::make('another-password'),
                'role' => 'admin', 'barangay' => 'Mabua',
            ]);
        });
        $this->post(route('register.store'), $this->registration())
            ->assertSessionHasErrors(['username' => Username::TAKEN_MESSAGE]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'concurrent@example.com', 'role' => 'admin']);
        $this->assertDatabaseMissing('users', ['email' => 'resident@example.com']);
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_username_and_email_log_into_the_same_account_and_preserve_permissions(string $role): void
    {
        $user = User::factory()->create(['username' => 'Juan.DelaCruz', 'role' => $role]);
        foreach (['  JUAN.DELACRUZ  ', $user->email] as $identifier) {
            $this->withSession(['url.intended' => route('reservations.index')]);
            $before = session()->getId();
            $this->login($identifier)->assertRedirect(route('reservations.index'));
            $this->assertAuthenticatedAs($user);
            $this->assertNotSame($before, session()->getId());
            $this->get(route('admins.create'))->assertStatus($role === 'super_admin' ? 200 : 403);
            $this->assertSame($role, $user->fresh()->role);
            $this->assertSame('Washington', $user->fresh()->barangay);
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
        Notification::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_existing_accounts_without_usernames_still_log_in_with_email(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->assertNull($user->username);
        $this->login($user->email)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->username);
    }

    public function test_invalid_credentials_are_generic_and_do_not_flash_passwords_or_send_codes(): void
    {
        $user = User::factory()->create(['username' => 'resident']);
        $user->forceFill(['two_factor_method' => 'email'])->save();
        foreach (['resident', $user->email, 'unknown', 'missing@example.com', 'invalid@', 'a b'] as $identifier) {
            $this->login($identifier, 'incorrect')->assertRedirect(route('login'))
                ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records.'])
                ->assertSessionMissing('two_factor.login');
            $this->assertGuest();
            $this->assertNull(session('_old_input.password'));
        }
        Notification::assertNothingSent();
    }

    public function test_blank_identifier_cannot_match_a_legacy_account_with_a_null_username(): void
    {
        User::factory()->create();
        $this->login('   ')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_throttle_cannot_be_bypassed_by_switching_username_and_email(): void
    {
        $user = User::factory()->create(['username' => 'resident']);
        for ($i = 0; $i < 10; $i++) {
            $this->login($i % 2 ? $user->email : 'resident', 'incorrect')->assertRedirect(route('login'));
        }
        $this->login('resident')->assertStatus(429);
        $this->login($user->email)->assertStatus(429);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->login('resident')->assertRedirect(route('dashboard'));
    }

    #[DataProvider('roles')]
    public function test_existing_users_can_set_and_edit_only_their_own_username_in_profile(string $role): void
    {
        $other = User::factory()->create(['username' => 'other', 'barangay' => 'Mabua']);
        $user = User::factory()->create(['role' => $role]);
        $verification = $user->email_verified_at;
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('name="username"', false)->assertSee($user->name)->assertSee($user->email)->assertSee($user->barangay);
        $data = ['name' => $user->name, 'email' => $user->email, 'username' => ' MY.Username ',
            'id' => $other->id, 'role' => 'super_admin', 'barangay' => 'Mabua'];
        $this->patch(route('profile.update'), $data)->assertSessionHasNoErrors();
        $this->assertSame('my.username', $user->fresh()->username);
        $this->assertSame($role, $user->fresh()->role);
        $this->assertSame('Washington', $user->fresh()->barangay);
        $this->assertSame('other', $other->fresh()->username);
        $this->assertTrue($verification->equalTo($user->fresh()->email_verified_at));
        $this->get(route('profile.edit'))->assertSee('value="my.username"', false);
        $this->patch(route('profile.update'), $data)->assertSessionHasNoErrors();
        $this->patch(route('profile.update'), array_merge($data, ['username' => 'OTHER']))
            ->assertSessionHasErrors(['username' => Username::TAKEN_MESSAGE]);
        $this->assertSame('my.username', $user->fresh()->username);
        $this->patch(route('profile.update'), array_merge($data, ['username' => $user->email]))
            ->assertSessionHasErrors('username');
        $this->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email])
            ->assertSessionHasNoErrors();
        $this->assertSame('my.username', $user->fresh()->username);
        $this->patch(route('profile.update'), array_merge($data, ['username' => ' New.Username ']))
            ->assertSessionHasNoErrors();
        $this->post(route('logout'));
        $this->login('my.username')->assertSessionHasErrors('email');
        $this->login('new.username')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Notification::assertNothingSent();
    }

    public function test_profile_can_clear_a_username_without_affecting_email_login(): void
    {
        $user = User::factory()->create(['username' => 'resident']);
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'username' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->username);
        $this->post(route('logout'));
        $this->login('resident')->assertSessionHasErrors('email');
        $this->login($user->email)->assertRedirect(route('dashboard'));
    }

    public function test_profile_race_returns_an_error_and_preserves_the_original_account(): void
    {
        $user = User::factory()->create(['username' => 'original']);
        $originalName = $user->name;
        Event::listen('eloquent.updating: '.User::class, function (User $updating) {
            DB::table('users')->insert([
                'name' => 'Concurrent Account', 'username' => $updating->username,
                'email' => 'concurrent@example.com', 'password' => Hash::make('another-password'),
            ]);
        });
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Changed Name', 'email' => $user->email, 'username' => 'new.name',
        ])->assertSessionHasErrors(['username' => Username::TAKEN_MESSAGE]);
        $this->assertSame('original', $user->fresh()->username);
        $this->assertSame($originalName, $user->fresh()->name);
        $this->assertDatabaseHas('users', ['email' => 'concurrent@example.com', 'username' => 'new.name']);
    }

    #[DataProvider('roles')]
    public function test_username_login_preserves_two_factor_challenge_for_every_role(string $role): void
    {
        $user = User::factory()->create(['username' => 'resident', 'role' => $role]);
        $user->forceFill(['two_factor_method' => 'email'])->save();
        $this->login('RESIDENT')->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertSame($user->id, session('two_factor.login.user_id'));
        $this->assertSame($user->email, TwoFactorChallenge::sole()->destination);
        $code = Notification::sent($user, SecurityCode::class)->last()->toMail($user)->viewData['code'];
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->withSession(['url.intended' => route('reservations.index')]);
        $this->post(route('two-factor.verify'), ['code' => $code])
            ->assertRedirect(route('reservations.index'))->assertSessionMissing('two_factor.login');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_unverified_username_login_still_requires_registered_email_verification(): void
    {
        $user = User::factory()->unverified()->create(['username' => 'resident']);
        $this->login('resident')->assertRedirect(route('verification.notice'));
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->patch(route('profile.update'), ['username' => 'changed'])->assertRedirect(route('verification.notice'));
        $this->assertSame('resident', $user->fresh()->username);
        $this->post(route('verification.send'))->assertSessionHasNoErrors();
        $this->assertSame($user->email, TwoFactorChallenge::sole()->destination);
        $code = Notification::sent($user, SecurityCode::class)->last()->toMail($user)->viewData['code'];
        $this->withSession(['url.intended' => route('dashboard')]);
        $this->post(route('verification.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_deactivated_username_login_is_blocked_before_sending_two_factor_codes(): void
    {
        $user = User::factory()->create(['username' => 'resident']);
        $user->forceFill(['is_active' => false, 'two_factor_method' => 'email'])->save();
        $this->login('resident')->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE])
            ->assertSessionMissing('two_factor.login');
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_username_login_preserves_password_rehashing(): void
    {
        $user = User::factory()->create(['username' => 'resident']);
        Hash::driver()->setRounds(6);
        $this->assertTrue(Hash::needsRehash($user->password));
        $this->login('resident')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertFalse(Hash::needsRehash($user->fresh()->password));
    }

    public function test_email_identifier_and_similar_username_select_only_their_respective_accounts(): void
    {
        $usernameOwner = User::factory()->create(['username' => 'resident', 'email' => 'other@example.com']);
        $emailOwner = User::factory()->create(['email' => 'resident@example.com']);
        $this->login('resident')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($usernameOwner);
        $this->post(route('logout'));
        $this->login('resident@example.com')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($emailOwner);
    }

    public function test_username_change_preserves_enabled_two_factor_and_verified_email(): void
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;
        $user->forceFill(['two_factor_method' => 'email'])->save();
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'username' => 'resident',
        ])->assertSessionHasNoErrors();
        $this->assertSame('email', $user->fresh()->two_factor_method);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->patch(route('profile.update'), [
            'name' => $user->name, 'email' => 'changed@example.com', 'username' => 'changed',
        ])->assertSessionHasErrors('email');
        $this->assertSame('resident', $user->fresh()->username);
        $this->assertSame($originalEmail, $user->fresh()->email);
    }

    public function test_password_reset_uses_email_and_keeps_both_login_methods(): void
    {
        $user = User::factory()->create(['username' => 'resident']);
        $this->post(route('password.email'), ['email' => 'resident'])->assertSessionHasErrors('email');
        Notification::assertNothingSent();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;
        $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $token, 'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertRedirect(route('login'));
        $this->assertSame('resident', $user->fresh()->username);
        foreach (['resident', $user->email] as $identifier) {
            $this->login($identifier, 'new-password123')->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
        }
    }

    public function test_migration_preserves_existing_accounts_and_allows_multiple_null_usernames(): void
    {
        $migration = require database_path('migrations/2026_10_11_000001_add_username_to_users_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $users = User::factory()->count(3)->create();
        $before = DB::table('users')->orderBy('id')->get()->toArray();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', 'username'));
        $after = DB::table('users')->orderBy('id')->get()->map(function ($user) {
            $this->assertNull($user->username);
            unset($user->username);

            return $user;
        })->toArray();
        $this->assertEquals($before, $after);
        $this->assertDatabaseCount('users', 3);
        $this->login($users->first()->email)->assertRedirect(route('dashboard'));
        $migration->down();
        $this->assertEquals($before, DB::table('users')->orderBy('id')->get()->toArray());
        $migration->up();
    }

    public function test_login_and_registration_views_expose_username_fields_in_the_shared_design(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Username or Email')
            ->assertSee('name="email" type="text"', false)->assertSee('autocomplete="username"', false)
            ->assertSee('Forgot Password?');
        $this->get(route('register'))->assertOk()->assertSee('name="username"', false)
            ->assertSee('Email Address')->assertSee('Full Name')->assertSee('Barangay');
    }
}
