<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
    }

    public function test_all_roles_have_profile_navigation_and_one_security_location(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk()
                ->assertSee('Personal Information')->assertSee('Change Password')
                ->assertSee('Account Information')->assertSee('id="security"', false)
                ->assertSee('Email Verification: <strong>Verified</strong>', false)
                ->assertSee($user->maskedTwoFactorDestination());
            preg_match('/<nav\b[\s\S]*?<\/nav>/', $response->getContent(), $nav);
            $this->assertStringContainsString(route('profile.edit'), $nav[0]);
            $this->assertStringNotContainsString('Settings', $nav[0]);
            $this->assertStringNotContainsString('/settings/security', $nav[0]);
            $this->get(route('settings.security'))->assertRedirect(route('profile.edit'));
        }
    }

    public function test_password_confirmation_is_collapsed_until_a_security_action_is_opened(): void
    {
        $user = User::factory()->create();
        foreach ([null, 'email'] as $method) {
            $user->two_factor_method = $method;
            $user->save();
            $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//article[@id="security"]//details[not(@open)]//input[@name="current_password"]')->length);
            $this->assertSame(0, $xpath->query('//article[@id="security"]//input[@name="current_password" and not(ancestor::details)]')->length);
        }
    }

    public function test_security_password_errors_are_separate_from_profile_password_errors(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->from(route('profile.edit'))
            ->post(route('two-factor.setup'), ['current_password' => 'incorrect'])->assertRedirect(route('profile.edit'));
        $response->assertSessionHasErrors('current_password', null, 'security');
        $errors = session('errors');
        $this->assertFalse($errors->getBag('default')->has('current_password'));
        $page = $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('profile.edit'))->assertOk();
        $this->assertTrue($page->viewData('securityErrors')->has('current_password'), 'Controller receives security errors');
        $html = $page->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        preg_match('/<details[^>]*>/', $html, $details);
        $this->assertSame(1, (new \DOMXPath($dom))->query('//article[@id="security"]//details[@open]')->length, $details[0] ?? 'Details missing');
        $this->assertFalse($user->fresh()->twoFactorEnabled());
    }

    public function test_existing_profile_name_and_password_updates_still_work(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.update'), [
            'name' => 'Updated Name', 'email' => $user->email,
        ])->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->assertSame('Updated Name', $user->fresh()->name);
        $this->put(route('profile.password.update'), [
            'current_password' => 'password', 'password' => 'new-password123', 'password_confirmation' => 'new-password123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
    }

    public function test_successful_setup_and_disable_always_return_to_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('two-factor.setup'), ['current_password' => 'password'])
            ->assertRedirect(route('profile.edit').'#security');
        $this->get(route('profile.edit'))->assertSee('Verify and Enable');
        $this->post(route('two-factor.setup.cancel'))->assertRedirect(route('profile.edit').'#security');
        $user->two_factor_method = 'email';
        $user->save();
        $this->actingAs($user)->delete(route('two-factor.disable'), ['current_password' => 'password'])
            ->assertRedirect(route('profile.edit').'#security')
            ->assertSessionHas('security_status', 'Two-factor authentication has been disabled.');
    }

    public function test_legacy_security_link_and_profile_keep_auth_and_verification_protection(): void
    {
        $this->get(route('settings.security'))->assertRedirect(route('login'));
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create());
        $this->get(route('settings.security'))->assertRedirect(route('verification.notice'));
        $this->get(route('profile.edit'))->assertRedirect(route('verification.notice'));
    }
}
