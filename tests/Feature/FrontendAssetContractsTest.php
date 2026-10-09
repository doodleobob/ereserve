<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FrontendAssetContractsTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function scripts(DOMXPath $document): array
    {
        $paths = [];
        foreach ($document->query('//script[@src]') as $script) {
            $paths[] = parse_url($script->getAttribute('src'), PHP_URL_PATH);
            $this->assertTrue($script->hasAttribute('defer'));
        }

        return $paths;
    }

    public function test_reservation_pages_keep_script_order_and_role_specific_filter_handlers(): void
    {
        foreach (['user', 'admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'barangay' => 'Taft']);
            $html = $this->actingAs($user)->get(route('reservations.index'))->assertOk()->getContent();
            $document = $this->document($html);
            $pageScripts = $role === 'user'
                ? ['/js/page-filters.js', '/js/resident-reservations.js']
                : ['/js/reservation-datatable.js', '/js/payment-confirmation.js'];
            $this->assertSame(['/js/modals.js', ...$pageScripts, '/js/notifications.js', '/js/facility-gallery.js'], $this->scripts($document));
            $this->assertSame(0, $document->query('//main//script[@src]')->length);
            $this->assertSame(1, substr_count($html, "navigator.serviceWorker.register('/sw.js')"));
        }
    }

    public function test_analytics_css_loads_in_head_and_chart_data_precedes_its_scripts(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'barangay' => 'Taft']);
            $superAdmin = $role === 'super_admin';
            $html = $this->actingAs($user)->get(route($superAdmin ? 'super-admin.analytics' : 'analytics'))->assertOk()->getContent();
            $document = $this->document($html);
            $styles = [];
            foreach ($document->query('//head/link[@rel="stylesheet"]') as $link) {
                $styles[] = parse_url($link->getAttribute('href'), PHP_URL_PATH);
            }
            $this->assertSame(['/css/app.css', '/css/admin-analytics.css', ...($superAdmin ? ['/css/super-admin-analytics.css'] : [])], $styles);
            $this->assertSame(0, $document->query('//body//link[@rel="stylesheet"]')->length);
            $this->assertSame(['/js/modals.js', '/js/vendor/chart.umd.min.js', $superAdmin ? '/js/super-admin-analytics.js' : '/js/admin-analytics.js', '/js/notifications.js', '/js/facility-gallery.js'], $this->scripts($document));
            $dataId = $superAdmin ? 'super-admin-analytics-data' : 'admin-analytics-data';
            $payload = $document->query('//script[@id="'.$dataId.'" and @type="application/json"]');
            $this->assertSame(1, $payload->length);
            $this->assertIsArray(json_decode($payload->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR));
            $this->assertLessThan(strpos($html, 'js/vendor/chart.umd.min.js'), strpos($html, $dataId));
        }
    }

    public function test_shared_pagination_preserves_filters_ajax_hooks_and_accessible_current_page(): void
    {
        $paginator = new LengthAwarePaginator([], 100, 10, 3, ['path' => '/payments', 'query' => ['status' => 'paid', 'sort' => 'id']]);
        $html = Blade::render('<x-table-pagination :paginator="$paginator" label="Payments table pagination" />', compact('paginator'));
        $document = $this->document($html);
        $this->assertSame(1, $document->query('//nav[@class="reservation-table-pagination" and @aria-label="Payments table pagination"]')->length);
        $this->assertSame('3', trim($document->query('//a[@aria-current="page"]')->item(0)->textContent));
        $links = $document->query('//nav/a');
        $this->assertSame(7, $links->length);
        foreach ($links as $link) {
            $this->assertTrue($link->hasAttribute('data-table-link'));
            $this->assertSame('/payments', parse_url($link->getAttribute('href'), PHP_URL_PATH));
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame('paid', $query['status']);
            $this->assertSame('id', $query['sort']);
            $this->assertContains((int) $query['page'], [1, 2, 3, 4, 5]);
        }
    }
}
