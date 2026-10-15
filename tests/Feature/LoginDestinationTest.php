<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SecurityCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginDestinationTest extends TestCase
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

    public static function invalidDestinations(): array
    {
        $cases = [];
        foreach (['user', 'admin', 'super_admin'] as $role) {
            foreach (['/sw.js', '/sw.js?v=15', '/%73w.js', '/manifest.json', '/css/app.css', '/login', '/logout', '/missing-page'] as $path) {
                $cases[$role.' '.$path] = [$role, $path];
            }
        }

        return $cases;
    }

    private function assertDashboard(User $user): void
    {
        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertOk()
            ->assertViewIs($user->role === 'user' ? 'dashboards.resident' : 'dashboards.operations')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('roles')]
    public function test_login_without_an_intended_url_opens_the_role_dashboard(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertDashboard($user);
    }

    #[DataProvider('invalidDestinations')]
    public function test_stale_asset_and_non_application_destinations_fall_back_to_the_role_dashboard(string $role, string $path): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->withSession(['url.intended' => url($path)])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');
        $this->assertDashboard($user);
    }

    public function test_a_guest_redirect_saved_from_a_worker_referrer_cannot_become_the_login_destination(): void
    {
        $this->from(url('/sw.js'))->post(route('logout'))
            ->assertRedirect(route('login'))->assertSessionHas('url.intended', url('/sw.js'));
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');
        $this->assertDashboard($user);
    }

    #[DataProvider('roles')]
    public function test_valid_intended_navigation_preserves_queries_and_fragments(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        foreach (['/reservations?status=pending#history', route('profile.edit').'#security'] as $destination) {
            $this->withSession(['url.intended' => $destination])
                ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect(url($destination))->assertSessionMissing('url.intended');
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
        }
    }

    public static function unsafeDestinations(): array
    {
        return [['https://example.test/dashboard'], ['//example.test/dashboard'],
            ['/sw\\.js'], [''], [['/sw.js']], [123]];
    }

    #[DataProvider('unsafeDestinations')]
    public function test_external_or_malformed_destinations_are_discarded(mixed $destination): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => $destination])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');
        $this->assertDashboard($user);
    }

    #[DataProvider('roles')]
    public function test_two_factor_login_discards_a_stale_worker_destination(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'two_factor_method' => 'email']);
        $this->withSession(['url.intended' => url('/sw.js')])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $code = Notification::sent($user, SecurityCode::class)->last()->toMail($user)->viewData['code'];
        $this->post(route('two-factor.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');
        $this->assertDashboard($user);
    }

    #[DataProvider('roles')]
    public function test_unverified_login_and_verification_cannot_redirect_to_the_worker(string $role): void
    {
        $user = User::factory()->unverified()->create(['role' => $role]);
        $this->withSession(['url.intended' => url('/sw.js')])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('verification.notice'))->assertSessionMissing('url.intended');
        $user->sendEmailVerificationNotification();
        $code = Notification::sent($user, SecurityCode::class)->last()->toMail($user)->viewData['code'];
        $this->withSession(['url.intended' => url('/sw.js')])
            ->post(route('verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');
        $this->assertDashboard($user);
    }
}
