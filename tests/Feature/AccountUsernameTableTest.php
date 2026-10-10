<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountUsernameTableTest extends TestCase
{
    use RefreshDatabase;

    public static function tables(): array
    {
        return [
            'barangay resident management' => ['admin', 'residents', 'user'],
            'super admin management' => ['super_admin', 'admins', 'admin'],
            'super admin resident oversight' => ['super_admin', 'residents', 'user'],
        ];
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function manager(string $role): User
    {
        return User::factory()->create(['role' => $role, 'barangay' => 'Washington']);
    }

    #[DataProvider('tables')]
    public function test_tables_display_username_next_to_name_and_keep_existing_actions(string $managerRole, string $route, string $accountRole): void
    {
        $account = User::factory()->create(['role' => $accountRole, 'username' => 'juan.resident']);
        $legacy = User::factory()->create(['role' => $accountRole]);
        $response = $this->actingAs($this->manager($managerRole))->get(route($route.'.index'))->assertOk();
        $document = $this->document($response->getContent());
        $table = '//section[contains(@class, "account-management-card")]//table';
        $headers = $document->query($table.'//thead/tr/th');
        $this->assertSame(8, $headers->length);
        foreach (['Name', 'Username', 'Email'] as $index => $label) {
            $this->assertStringStartsWith($label, trim($headers->item($index)->textContent));
        }
        foreach ([$account->email => 'juan.resident', $legacy->email => 'Not set'] as $email => $username) {
            $row = $table.'//tbody/tr[td[3][normalize-space()="'.$email.'"]]';
            $this->assertSame($username, trim($document->query($row.'/td[2]')->item(0)->textContent));
            $this->assertSame(8, $document->query($row.'/td')->length);
            $this->assertSame(1, $document->query($row.'//a[@data-account-view]')->length);
            $this->assertSame($managerRole === 'super_admin' && $route === 'residents' ? 0 : 1,
                $document->query($row.'//form[@data-account-status]')->length);
        }
        $usernameHeader = $headers->item(1);
        $this->assertSame('none', $usernameHeader->getAttribute('aria-sort'));
        $link = $usernameHeader->getElementsByTagName('a')->item(0);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertSame('username', $query['sort']);
        $this->assertSame('asc', $query['direction']);
        $this->assertSame(1, $document->query($table.'/ancestor::div[contains(@class, "admin-reservation-table")]')->length);
    }

    #[DataProvider('tables')]
    public function test_username_search_stays_within_barangay_and_role_scope(string $managerRole, string $route, string $accountRole): void
    {
        $local = User::factory()->create(['role' => $accountRole, 'username' => 'lookup.local', 'barangay' => 'Washington']);
        $foreign = User::factory()->create(['role' => $accountRole, 'username' => 'lookup.foreign', 'barangay' => 'Mabua']);
        $wrongRole = User::factory()->create(['role' => $accountRole === 'user' ? 'admin' : 'user', 'username' => 'lookup.wrong']);
        User::factory()->create(['role' => 'super_admin', 'username' => 'lookup.super']);
        $this->actingAs($this->manager($managerRole));
        $response = $this->get(route($route.'.index', ['search' => 'lookup']))->assertOk();
        $this->assertEqualsCanonicalizing($managerRole === 'admin' ? [$local->id] : [$local->id, $foreign->id],
            $response->viewData('accounts')->modelKeys());
        // Even a direct match must not broaden a Barangay Admin's tenant scope.
        $this->get(route($route.'.index', ['search' => $foreign->username, 'barangay' => 'Mabua']))->assertOk()
            ->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === ($managerRole === 'admin' ? [] : [$foreign->id]));
        $this->get(route($route.'.index', ['search' => $wrongRole->username]))
            ->assertViewHas('accounts', fn ($rows) => $rows->isEmpty());
        $this->get(route($route.'.index', ['search' => "' OR 1=1 --"]))
            ->assertViewHas('accounts', fn ($rows) => $rows->isEmpty());
        $this->get(route($route.'.index', ['search' => 'LOOKUP.LOCAL']))
            ->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$local->id]);
    }

    #[DataProvider('tables')]
    public function test_username_sorting_handles_legacy_accounts_and_toggles_direction(string $managerRole, string $route, string $accountRole): void
    {
        $zulu = User::factory()->create(['role' => $accountRole, 'username' => 'zulu']);
        $alpha = User::factory()->create(['role' => $accountRole, 'username' => 'alpha']);
        $legacy = User::factory()->create(['role' => $accountRole]);
        $otherLegacy = User::factory()->create(['role' => $accountRole]);
        $this->actingAs($this->manager($managerRole));
        foreach (['asc' => [$legacy->id, $otherLegacy->id, $alpha->id, $zulu->id],
            'desc' => [$zulu->id, $alpha->id, $otherLegacy->id, $legacy->id]] as $direction => $ids) {
            $response = $this->get(route($route.'.index', ['sort' => 'username', 'direction' => $direction]))->assertOk()
                ->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === $ids);
            $document = $this->document($response->getContent());
            $header = $document->query('//section[contains(@class, "account-management-card")]//thead/tr/th[2]')->item(0);
            $this->assertSame($direction === 'asc' ? 'ascending' : 'descending', $header->getAttribute('aria-sort'));
            parse_str(parse_url($header->getElementsByTagName('a')->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame($direction === 'asc' ? 'desc' : 'asc', $query['direction']);
            $this->assertSame('username', $query['sort']);
        }
        $this->get(route($route.'.index', ['sort' => 'username; DROP TABLE users']))->assertSessionHasErrors('sort');
    }

    #[DataProvider('tables')]
    public function test_username_pagination_preserves_search_filters_and_sorting(string $managerRole, string $route, string $accountRole): void
    {
        for ($i = 0; $i < 17; $i++) {
            User::factory()->create(['role' => $accountRole, 'username' => sprintf('filter.member.%02d', $i), 'is_active' => false]);
        }
        User::factory()->create(['role' => $accountRole, 'username' => 'filter.member.active']);
        User::factory()->create(['role' => $accountRole, 'username' => 'filter.member.foreign', 'barangay' => 'Mabua', 'is_active' => false]);
        $filters = ['search' => 'filter.member', 'status' => 'inactive', 'barangay' => 'Washington', 'sort' => 'username', 'direction' => 'desc'];
        $response = $this->actingAs($this->manager($managerRole))->get(route($route.'.index', $filters))->assertOk();
        $rows = $response->viewData('accounts');
        $this->assertSame(17, $rows->total());
        $this->assertSame(15, $rows->count());
        $this->assertSame('filter.member.16', $rows->first()->username);
        parse_str(parse_url($rows->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page' => '2'], $query);
        $this->get($rows->nextPageUrl())->assertOk()
            ->assertViewHas('accounts', fn ($rows) => $rows->currentPage() === 2
                && $rows->pluck('username')->all() === ['filter.member.01', 'filter.member.00']);
        $document = $this->document($response->getContent());
        $this->assertSame('username', $document->query('//form[@method="GET"]//input[@name="sort"]')->item(0)->getAttribute('value'));
    }

    #[DataProvider('tables')]
    public function test_empty_tables_span_all_eight_columns(string $managerRole, string $route, string $accountRole): void
    {
        $response = $this->actingAs($this->manager($managerRole))->get(route($route.'.index'))->assertOk();
        $document = $this->document($response->getContent());
        $cell = $document->query('//section[contains(@class, "account-management-card")]//tbody/tr/td')->item(0);
        $this->assertSame('8', $cell->getAttribute('colspan'));
        $this->assertSame('No accounts match your filters.', trim($cell->textContent));
    }

    public function test_username_display_is_escaped(): void
    {
        User::factory()->create(['username' => '<script>alert(1)</script>']);
        $this->actingAs($this->manager('admin'))->get(route('residents.index'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_payment_and_official_use_tables_have_no_username_columns(): void
    {
        $this->actingAs($this->manager('super_admin'));
        foreach (['payments.index', 'official-uses.index'] as $route) {
            $document = $this->document($this->get(route($route))->assertOk()->getContent());
            $headers = $document->query('//main//table//thead//th');
            $this->assertGreaterThan(0, $headers->length);
            foreach ($headers as $header) {
                $this->assertStringNotContainsString('Username', $header->textContent);
            }
        }
    }
}
