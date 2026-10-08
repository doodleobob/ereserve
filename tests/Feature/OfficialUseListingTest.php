<?php

namespace Tests\Feature;

use App\Http\Controllers\OfficialUseController;
use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OfficialUseListingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Facility $alpha;

    private Facility $beta;

    private Facility $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00', 'Asia/Manila'));
        $this->admin = User::factory()->create(['name' => 'Listing Administrator', 'role' => 'admin', 'barangay' => 'Taft']);
        $this->alpha = $this->resource('Taft', 'Alpha Court', 'alpha-secret-slug');
        $this->beta = $this->resource('Taft', 'Beta Equipment', 'beta-equipment');
        $this->foreign = $this->resource('Washington', 'Foreign Hall', 'foreign-hall');

        $this->use($this->alpha, '2026-10-10', '09:00', 'Alpha Workshop', 'active');
        $this->use($this->beta, '2026-10-11', '14:00', 'Beta Assembly', 'conflict');
        $this->use($this->alpha, '2026-10-11', '08:00', 'Alpha Workshop', 'cancelled');
        $this->use($this->alpha, '2026-10-11', '14:00', 'Alpha Workshop', 'active');
        $this->use($this->beta, '2026-10-09', '23:30', 'Overnight Assembly', 'active', ['end_time' => '00:30']);
        $this->use($this->foreign, '2026-10-12', '10:00', 'Foreign Only', 'active', ['barangay' => 'Washington']);
        // Preserve the stored tenant rule even for a legacy row whose facility is elsewhere.
        $this->use($this->foreign, '2026-10-08', '10:00', 'Legacy Schedule', 'active');
        $this->actingAs($this->admin);
    }

    public function test_default_listing_order_eager_loading_resource_selection_and_read_only_behavior(): void
    {
        $before = [];
        foreach (['official_uses', 'official_use_conflicts', 'reservations', 'notifications', 'users', 'facilities'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $response = $this->listing()->assertViewIs('official-uses.index');
        $rows = $response->viewData('uses');
        $this->assertInstanceOf(LengthAwarePaginator::class, $rows);
        $this->assertSame([4, 2, 3, 1, 5, 7], $rows->getCollection()->modelKeys());
        $this->assertSame(6, $rows->total());
        $this->assertSame(10, $rows->perPage());
        $this->assertSame(1, $rows->currentPage());
        foreach ($rows as $row) {
            $this->assertTrue($row->relationLoaded('facility'));
            $this->assertInstanceOf(Facility::class, $row->facility);
        }
        $this->assertSame([$this->alpha->id, $this->beta->id], $response->viewData('resources')->modelKeys());
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        Notification::assertNothingSent();
    }

    public function test_search_preserves_supported_fields_id_syntax_whitespace_and_wildcards(): void
    {
        foreach ([
            '  Alpha Workshop  ' => [1, 3, 4],
            'Alpha Court' => [1, 3, 4],
            'Beta Equipment' => [2, 5],
            '1' => [1], '#1' => [1], '#001' => [1], '##1' => [1],
            'Foreign' => [7], '#6' => [],
            'Listing Administrator' => [], 'alpha-secret-slug' => [], '2026-10-11' => [],
            '0' => [1, 2, 3, 4, 5, 7], '%' => [1, 2, 3, 4, 5, 7], '_' => [1, 2, 3, 4, 5, 7],
            '   ' => [1, 2, 3, 4, 5, 7],
        ] as $search => $expected) {
            $this->assertIds($expected, ['search' => (string) $search, 'sort' => 'id', 'direction' => 'asc']);
        }
    }

    public function test_filters_combine_without_changing_tenant_scope_or_status_interpretation(): void
    {
        foreach ([
            [['facility_id' => $this->alpha->id], [1, 3, 4]],
            [['facility_id' => $this->foreign->id], [7]],
            [['facility_id' => 999999], []],
            [['status' => 'active'], [1, 4, 5, 7]],
            [['status' => 'conflict'], [2]],
            [['status' => 'all'], [1, 2, 3, 4, 5, 7]],
            [['search' => 'Alpha', 'facility_id' => $this->alpha->id, 'status' => 'active', 'from_date' => '2026-10-11', 'to_date' => '2026-10-11'], [4]],
            [['barangay' => 'Washington', 'created_by' => 999999], [1, 2, 3, 4, 5, 7]],
        ] as [$filters, $expected]) {
            $this->assertIds($expected, $filters + ['sort' => 'id', 'direction' => 'asc']);
        }
        $this->getJson(route('official-uses.index', ['status' => 'cancelled']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_date_filters_are_inclusive_start_dates_and_reversed_ranges_are_empty(): void
    {
        foreach ([
            [['from_date' => '2026-10-10', 'to_date' => '2026-10-11'], [1, 2, 3, 4]],
            [['from_date' => '2026-10-11', 'to_date' => '2026-10-11'], [2, 3, 4]],
            [['from_date' => '2026-10-10'], [1, 2, 3, 4]],
            [['to_date' => '2026-10-09'], [5, 7]],
            [['from_date' => '2026-10-12', 'to_date' => '2026-10-08'], []],
        ] as [$filters, $expected]) {
            $this->assertIds($expected, $filters + ['sort' => 'id', 'direction' => 'asc']);
        }
    }

    public function test_every_sort_preserves_direction_and_existing_tie_breakers(): void
    {
        foreach ([
            'id' => [1, 2, 3, 4, 5, 7],
            'schedule' => [7, 5, 1, 3, 2, 4],
            'resource' => [1, 3, 4, 2, 5, 7],
            'purpose' => [1, 3, 4, 2, 7, 5],
            'status' => [1, 4, 5, 7, 3, 2],
        ] as $sort => $ascending) {
            $this->assertIds($ascending, ['sort' => $sort, 'direction' => 'asc']);
            $this->assertIds(array_reverse($ascending), ['sort' => $sort, 'direction' => 'desc']);
        }
        $this->assertIds([7, 5, 4, 3, 2, 1], ['sort' => 'id']);
    }

    public function test_nullable_filters_and_direction_keep_existing_defaults(): void
    {
        $filters = array_fill_keys(['search', 'facility_id', 'status', 'from_date', 'to_date', 'direction', 'page'], '');
        $this->assertIds([4, 2, 3, 1, 5, 7], $filters);

        $request = Request::create(route('official-uses.index'), 'GET', array_fill_keys(array_keys($filters), null));
        $request->setUserResolver(fn () => $this->admin);
        $rows = (new OfficialUseController)->index($request)->getData()['uses'];
        $this->assertSame([4, 2, 3, 1, 5, 7], $rows->getCollection()->modelKeys());
        $this->assertSame(10, $rows->perPage());
        $this->assertSame(1, $rows->currentPage());
    }

    public function test_empty_page_size_uses_fifteen_but_omitted_page_size_uses_ten(): void
    {
        $this->assertSame(10, $this->listing()->viewData('uses')->perPage());
        $this->assertSame(15, $this->listing(['per_page' => ''])->viewData('uses')->perPage());
        $request = Request::create(route('official-uses.index'), 'GET', ['per_page' => null]);
        $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(15, (new OfficialUseController)->index($request)->getData()['uses']->perPage());
    }

    public function test_empty_sort_uses_the_existing_default_after_http_input_normalization(): void
    {
        $this->assertIds([4, 2, 3, 1, 5, 7], ['sort' => '']);
    }

    public function test_explicit_null_sort_uses_the_existing_default(): void
    {
        $request = Request::create(route('official-uses.index'), 'GET', ['sort' => null]);
        $request->setUserResolver(fn () => $this->admin);
        $rows = (new OfficialUseController)->index($request)->getData()['uses'];
        $this->assertSame([4, 2, 3, 1, 5, 7], $rows->getCollection()->modelKeys());
    }

    public function test_pagination_preserves_sizes_pages_out_of_range_and_query_parameters(): void
    {
        for ($i = 8; $i <= 32; $i++) {
            $this->use($this->alpha, '2026-10-10', '09:00', 'Pagination Workshop', 'active');
        }
        foreach ([10, 25, 50, 100] as $size) {
            $rows = $this->listing(['per_page' => $size])->viewData('uses');
            $this->assertSame(31, $rows->total());
            $this->assertSame($size, $rows->perPage());
            $this->assertSame(min(31, $size), $rows->count());
        }
        $rows = $this->listing(['search' => 'Pagination', 'status' => 'active', 'sort' => 'id', 'direction' => 'asc', 'per_page' => 10, 'page' => 2])->viewData('uses');
        $this->assertSame(range(18, 27), $rows->getCollection()->modelKeys());
        $this->assertSame(25, $rows->total());
        $this->assertSame(2, $rows->currentPage());
        parse_str(parse_url($rows->url(3), PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'Pagination', 'status' => 'active', 'sort' => 'id', 'direction' => 'asc', 'per_page' => '10', 'page' => '3'], $query);
        $rows = $this->listing(['page' => 999])->viewData('uses');
        $this->assertSame(999, $rows->currentPage());
        $this->assertSame(31, $rows->total());
        $this->assertCount(0, $rows);
    }

    public function test_authorization_precedes_invalid_filter_validation(): void
    {
        $resident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $this->actingAs($resident)->getJson(route('official-uses.index', ['sort' => 'invalid', 'page' => 0, 'from_date' => 'invalid']))
            ->assertForbidden()->assertJsonMissingPath('errors');
        $this->get(route('official-uses.index', ['per_page' => 999]))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('official-uses.index', ['sort' => 'invalid', 'page' => 0, 'from_date' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'page', 'from_date']);
        $this->from(route('official-uses.index'))->get(route('official-uses.index', ['direction' => 'invalid']))
            ->assertRedirect(route('official-uses.index'))->assertSessionHasErrors('direction');
    }

    public function test_guest_unverified_and_deactivated_requests_keep_middleware_responses(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get(route('official-uses.index', ['sort' => 'invalid']))->assertRedirect(route('login'));
        $unverified = User::factory()->unverified()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($unverified)->get(route('official-uses.index', ['sort' => 'invalid']))->assertRedirect(route('verification.notice'));
        $inactive = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft', 'is_active' => false]);
        $this->actingAs($inactive)->get(route('official-uses.index', ['sort' => 'invalid']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_other_barangay_admin_is_scoped_by_stored_ownership_even_during_search(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($otherAdmin);
        $response = $this->assertIds([6], ['search' => 'Foreign', 'barangay' => 'Taft']);
        $this->assertSame([$this->foreign->id], $response->viewData('resources')->modelKeys());
        $this->assertIds([], ['search' => '#7']);
        $this->assertIds([], ['facility_id' => $this->alpha->id]);
    }

    public function test_super_admin_can_list_and_filter_all_tenants_with_global_resource_selection(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->actingAs($super);
        $response = $this->assertIds([1, 2, 3, 4, 5, 6, 7], ['sort' => 'id', 'direction' => 'asc']);
        $this->assertSame([$this->alpha->id, $this->beta->id, $this->foreign->id], $response->viewData('resources')->modelKeys());
        $this->assertIds([6, 7], ['search' => 'Foreign', 'facility_id' => $this->foreign->id, 'sort' => 'id', 'direction' => 'asc']);
        $this->assertIds([6], ['search' => '#6']);
    }

    private function listing(array $filters = []): TestResponse
    {
        return $this->get(route('official-uses.index').($filters ? '?'.http_build_query($filters) : ''))->assertOk();
    }

    private function assertIds(array $expected, array $filters): TestResponse
    {
        $response = $this->listing($filters);
        $rows = $response->viewData('uses');
        $this->assertSame($expected, $rows->getCollection()->modelKeys(), json_encode($filters));
        $this->assertSame(count($expected), $rows->total());

        return $response;
    }

    private function resource(string $barangay, string $name, string $slug): Facility
    {
        return Facility::create(['barangay' => $barangay, 'name' => $name, 'slug' => $slug, 'category' => 'Facility', 'description' => $name, 'capacity' => 50, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => 100]);
    }

    private function use(Facility $facility, string $date, string $start, string $purpose, string $status, array $overrides = []): OfficialUse
    {
        return OfficialUse::create(array_merge(['barangay' => 'Taft', 'facility_id' => $facility->id, 'date' => $date, 'start_time' => $start, 'end_time' => '16:00', 'purpose' => $purpose, 'status' => $status, 'created_by' => $this->admin->id], $overrides));
    }
}
