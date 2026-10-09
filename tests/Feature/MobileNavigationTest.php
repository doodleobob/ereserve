<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    public function test_drawer_reuses_the_exact_desktop_destinations_for_each_role(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'name' => 'María <Resident>', 'email' => $role.'@example.test', 'barangay' => 'Taft']);
            $this->actingAs($user);
            $document = $this->document($this->get(route('dashboard'))->assertOk()->getContent());
            $desktop = $document->query('//nav[@class="user-nav"]//a');
            $mobile = $document->query('//dialog[@id="mobile-navigation"]//nav/a');
            $expected = $role === 'user'
                ? ['Dashboard', 'Facilities', 'My Reservations', 'Profile']
                : [$role === 'admin' ? 'Admin Dashboard' : 'Super Admin Dashboard', 'Calendar', 'Reservation Management', 'Official Use', 'Payments', 'Facility Management', 'Resident Management', ...($role === 'super_admin' ? ['Admin Management'] : []), 'Analytics', 'Profile'];
            $this->assertCount(count($expected), $mobile);
            $this->assertCount(count($expected), $desktop);
            foreach ($mobile as $index => $link) {
                $this->assertSame($expected[$index], trim($link->textContent));
                $this->assertSame($desktop->item($index)->getAttribute('href'), $link->getAttribute('href'));
                $this->assertSame(1, $link->getElementsByTagName('svg')->length);
                $this->get($link->getAttribute('href'))->assertOk();
            }
            $profile = $document->query('//dialog[@id="mobile-navigation"]//*[@class="mobile-drawer-profile"]')->item(0);
            $this->assertStringContainsString($user->name, $profile->textContent);
            $this->assertStringContainsString($user->email, $profile->textContent);
            $this->assertStringContainsString('Barangay: Taft', $profile->textContent);
            $this->assertSame(0, $profile->getElementsByTagName('script')->length);
            $this->assertSame(1, $document->query('//*[@data-notifications]')->length);
            $this->assertSame(1, $document->query('//script[contains(@src, "/js/notifications.js")]')->length);
            $this->assertSame(1, $document->query('//script[contains(@src, "/js/mobile-navigation.js")]')->length);
            $this->assertSame('false', $document->query('//button[@aria-controls="mobile-navigation"]')->item(0)->getAttribute('aria-expanded'));
        }
    }

    public function test_active_drawer_section_follows_current_and_nested_routes(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $resident = User::factory()->create(['role' => 'user']);
        $this->actingAs($admin);
        foreach ([
            ['dashboard', [], 'Super Admin Dashboard'],
            ['calendar', [], 'Calendar'],
            ['reservations.index', [], 'Reservation Management'],
            ['official-uses.index', [], 'Official Use'],
            ['payments.index', [], 'Payments'],
            ['facilities', [], 'Facility Management'],
            ['residents.show', ['account' => $resident->id], 'Resident Management'],
            ['admins.create', [], 'Admin Management'],
            ['super-admin.analytics', [], 'Analytics'],
            ['profile.edit', [], 'Profile'],
        ] as [$route, $parameters, $label]) {
            $document = $this->document($this->get(route($route, $parameters))->assertOk()->getContent());
            $active = $document->query('//dialog[@id="mobile-navigation"]//a[@aria-current="page"]');
            $this->assertSame(1, $active->length, $route);
            $this->assertSame($label, trim($active->item(0)->textContent));
        }
    }

    public function test_resident_calendar_url_highlights_the_existing_dashboard_destination(): void
    {
        $resident = User::factory()->create(['role' => 'user']);
        $document = $this->document($this->actingAs($resident)->get(route('calendar'))->assertOk()->getContent());
        $active = $document->query('//dialog[@id="mobile-navigation"]//a[@aria-current="page"]');
        $this->assertSame(1, $active->length);
        $this->assertSame('Dashboard', trim($active->item(0)->textContent));
        $this->assertSame(route('dashboard'), $active->item(0)->getAttribute('href'));
    }

    public function test_mobile_and_desktop_logout_use_existing_post_route_and_csrf_for_every_role(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $document = $this->document($this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent());
            $forms = $document->query('//form[@action="'.route('logout').'"]');
            $this->assertSame(2, $forms->length);
            foreach ($forms as $form) {
                $this->assertSame('POST', $form->getAttribute('method'));
                $this->assertSame('Logout', trim($form->getElementsByTagName('button')->item(0)->textContent));
                $this->assertSame('_token', $form->getElementsByTagName('input')->item(0)->getAttribute('name'));
            }
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
            $this->get(route('dashboard'))->assertRedirect(route('login'));
        }
    }
}
