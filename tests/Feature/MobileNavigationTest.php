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

    public function test_header_profile_link_reuses_the_existing_profile_page_for_every_role(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => 'Taft']));
            foreach (['dashboard', 'profile.edit', 'reservations.index'] as $route) {
                $response = $this->get(route($route))->assertOk();
                $document = $this->document($response->getContent());
                $links = $document->query('//header//a[contains(@class, "profile-button")]');
                $this->assertSame(1, $links->length);
                $link = $links->item(0);
                $this->assertSame(route('profile.edit'), $link->getAttribute('href'));
                $this->assertSame('Profile Settings', $link->getAttribute('aria-label'));
                $this->assertSame('Profile Settings', $link->getAttribute('title'));
                $this->assertStringContainsString('header-icon-button', $link->getAttribute('class'));
                $this->assertSame(1, $document->query('//header//button[contains(@class, "header-icon-button") and contains(@class, "notification-bell")]')->length);
                $response->assertSee('/css/app.css?v='.hash_file('sha256', public_path('css/app.css')), false)
                    ->assertSee('/js/mobile-navigation.js?v='.hash_file('sha256', public_path('js/mobile-navigation.js')), false);
                $this->assertSame($route === 'profile.edit', $link->getAttribute('aria-current') === 'page');
                $this->assertSame($route === 'profile.edit', str_contains($link->getAttribute('class'), 'active'));
                $this->assertSame(0, $document->query('//*[@data-profile-navigation or @class="profile-navigation-links"]')->length);
                $this->assertSame(1, $document->query('//header//a[contains(@class, "profile-button")]/preceding-sibling::div[@data-notifications]')->length);
                $this->assertSame(1, $document->query('//header//a[contains(@class, "profile-button")]/following-sibling::form[@action="'.route('logout').'"]')->length);
                $this->assertSame(0, $document->query('//nav[@class="user-nav"]//a[@href="'.route('profile.edit').'"]')->length);
                if ($directory = getenv('HEADER_RENDER_DIR')) {
                    if (! is_dir($directory)) {
                        mkdir($directory, 0777, true);
                    }
                    $label = ['user' => 'resident', 'admin' => 'admin', 'super_admin' => 'super-admin'][$role];
                    $page = match ($route) {
                        'dashboard' => 'dashboard',
                        'profile.edit' => 'profile',
                        default => 'reservations',
                    };
                    file_put_contents($directory.'/'.$label.'-'.$page.'.html', $response->getContent());
                }
            }
        }
    }

    public function test_drawer_reuses_the_exact_desktop_destinations_for_each_role(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'name' => 'María <Resident>', 'email' => $role.'@example.test', 'barangay' => 'Taft']);
            $this->actingAs($user);
            $document = $this->document($this->get(route('dashboard'))->assertOk()->getContent());
            $desktop = $document->query('//nav[@class="user-nav"]//a');
            $this->assertSame($role === 'user' ? 1 : 0, $document->query('//nav[@class="user-nav"]/*[contains(@class, "nav-inner-resident")]')->length);
            $mobile = $document->query('//dialog[@id="mobile-navigation"]//nav//a');
            $expected = $role === 'user'
                ? ['Dashboard', 'Facilities', 'Equipment', 'All Schedules', 'My Reservations']
                : [$role === 'admin' ? 'Admin Dashboard' : 'Super Admin Dashboard', 'All Schedules', 'Reservation Management', 'Official Use', 'Payments', 'Facility Management', 'Equipment Management', 'Resident Management', ...($role === 'super_admin' ? ['Admin Management'] : []), 'Analytics'];
            $this->assertCount(count($expected), $mobile);
            $this->assertCount(count($expected), $desktop);
            foreach ($mobile as $index => $link) {
                $this->assertSame($expected[$index], trim($link->textContent));
                $this->assertSame($desktop->item($index)->getAttribute('href'), $link->getAttribute('href'));
                $this->assertSame(1, $link->getElementsByTagName('svg')->length);
                $this->assertSame(1, $desktop->item($index)->getElementsByTagName('svg')->length);
                $this->assertSame(
                    $document->document->saveXML($link->getElementsByTagName('svg')->item(0)),
                    $document->document->saveXML($desktop->item($index)->getElementsByTagName('svg')->item(0))
                );
                $this->get($link->getAttribute('href'))->assertOk();
            }
            $profile = $document->query('//dialog[@id="mobile-navigation"]//*[@class="mobile-drawer-profile"]')->item(0);
            $this->assertStringContainsString($user->name, $profile->textContent);
            $this->assertStringContainsString($user->email, $profile->textContent);
            $this->assertSame('eReserve', $document->query('//*[@class="mobile-drawer-title"]')->item(0)->textContent);
            $elements = $document->query('//dialog[@id="mobile-navigation"]//*[@class="mobile-drawer-title" or @class="mobile-drawer-avatar" or @class="mobile-drawer-name" or @class="mobile-drawer-email" or @class="mobile-drawer-barangay"]');
            $this->assertSame(['eReserve', mb_strtoupper(mb_substr($user->name, 0, 1)), $user->name, $user->email, 'Barangay: Taft'],
                array_map(fn ($element) => trim($element->textContent), iterator_to_array($elements)));
            $this->assertStringContainsString('Barangay: Taft', $profile->textContent);
            $this->assertSame(0, $profile->getElementsByTagName('script')->length);
            $this->assertSame(1, $document->query('//*[@data-notifications]')->length);
            $this->assertSame(1, $document->query('//script[contains(@src, "/js/notifications.js")]')->length);
            $this->assertSame(1, $document->query('//script[contains(@src, "/js/mobile-navigation.js")]')->length);
            $this->assertSame(1, $document->query('//script[contains(@src, "/js/resources-navigation.js")]')->length);
            foreach ($document->query('//details[@data-resources-navigation]/summary') as $summary) {
                $this->assertSame(1, $summary->getElementsByTagName('svg')->length);
                $this->assertSame(1, $document->query('//*[@id="'.$summary->getAttribute('aria-controls').'"]')->length);
            }
            $this->assertSame('false', $document->query('//button[@aria-controls="mobile-navigation"]')->item(0)->getAttribute('aria-expanded'));
            $this->assertSame(0, $document->query('//*[contains(@class, "mobile-drawer-close")]')->length);
            $this->assertSame(1, $document->query('//dialog[@id="mobile-navigation"][@aria-modal="true"]')->length);
        }
    }

    public function test_active_drawer_section_follows_current_and_nested_routes(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $resident = User::factory()->create(['role' => 'user']);
        $this->actingAs($admin);
        foreach ([
            ['dashboard', [], 'Super Admin Dashboard'],
            ['calendar', [], 'All Schedules'],
            ['reservations.index', [], 'Reservation Management'],
            ['official-uses.index', [], 'Official Use'],
            ['payments.index', [], 'Payments'],
            ['facilities', [], 'Facility Management'],
            ['equipment', [], 'Equipment Management'],
            ['residents.show', ['account' => $resident->id], 'Resident Management'],
            ['admins.create', [], 'Admin Management'],
            ['super-admin.analytics', [], 'Analytics'],
        ] as [$route, $parameters, $label]) {
            $document = $this->document($this->get(route($route, $parameters))->assertOk()->getContent());
            $active = $document->query('//dialog[@id="mobile-navigation"]//a[@aria-current="page"]');
            $this->assertSame(1, $active->length, $route);
            $this->assertSame($label, trim($active->item(0)->textContent));
        }
    }

    public function test_resident_calendar_url_highlights_all_schedules(): void
    {
        $resident = User::factory()->create(['role' => 'user']);
        $document = $this->document($this->actingAs($resident)->get(route('calendar'))->assertOk()->getContent());
        $active = $document->query('//dialog[@id="mobile-navigation"]//a[@aria-current="page"]');
        $this->assertSame(1, $active->length);
        $this->assertSame('All Schedules', trim($active->item(0)->textContent));
        $this->assertSame(route('calendar'), $active->item(0)->getAttribute('href'));
    }

    public function test_my_reservations_is_the_only_active_resident_drawer_link(): void
    {
        $resident = User::factory()->create(['role' => 'user']);
        $document = $this->document($this->actingAs($resident)->get(route('reservations.index'))->assertOk()->getContent());
        $active = $document->query('//dialog[@id="mobile-navigation"]//a[contains(concat(" ", normalize-space(@class), " "), " active ")]');
        $this->assertSame(1, $active->length);
        $this->assertSame('My Reservations', trim($active->item(0)->textContent));
        $this->assertSame('page', $active->item(0)->getAttribute('aria-current'));
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
