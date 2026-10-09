<?php

namespace Tests\Feature;

use App\Http\Controllers\ReservationPageController;
use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\OfficialUseConflict;
use App\Models\Reservation;
use App\Models\User;
use ErrorException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ResidentReservationListingTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private User $outsider;

    private Facility $alpha;

    private Facility $beta;

    private const DEFAULT_IDS = [7, 6, 3, 2, 1, 4, 5, 8];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['name' => 'History Resident', 'email' => 'history@example.test', 'role' => 'user', 'barangay' => 'Washington']);
        $this->outsider = User::factory()->create(['name' => 'Other Resident', 'email' => 'other@example.test', 'role' => 'user', 'barangay' => 'Washington']);
        $this->alpha = $this->resource('Taft', 'Current Alpha Court', 'alpha-court');
        $this->beta = $this->resource('Washington', 'Renamed Equipment', 'beta-equipment');

        $this->booking(['reservation_date' => '2026-10-10', 'start_time' => '09:00', 'purpose' => 'Community Workshop']);
        $this->booking(['facility_id' => $this->beta->id, 'barangay' => 'Washington', 'facility_slug' => $this->beta->slug, 'facility_name' => 'Beta Snapshot Equipment', 'reservation_date' => '2026-10-11', 'start_time' => '08:00', 'status' => 'accepted', 'purpose' => 'Assembly 0', 'total_payment' => '120.00']);
        $accepted = $this->booking(['barangay' => 'Washington', 'reservation_date' => '2026-10-11', 'start_time' => '14:00', 'status' => 'accepted', 'purpose' => 'Community Assembly', 'total_payment' => '150.00']);
        $this->booking(['facility_id' => null, 'facility_name' => 'Legacy Hall', 'reservation_date' => '2026-10-09', 'start_time' => '23:30', 'end_time' => '00:30', 'status' => 'cancelled', 'purpose' => 'Cancelled Gathering', 'cancellation_reason' => 'Official Use', 'total_payment' => '125.50']);
        $this->booking(['facility_id' => null, 'barangay' => 'Washington', 'facility_slug' => 'missing-resource', 'facility_name' => 'Legacy Hall', 'reservation_date' => '2026-10-08', 'status' => 'rejected', 'purpose' => 'Rejected Gathering', 'hourly_rate_snapshot' => null]);
        $this->booking(['reservation_date' => '2026-10-12', 'start_time' => '07:00', 'purpose' => 'Extra Workshop', 'total_payment' => '0.00']);
        $this->booking(['facility_id' => $this->beta->id, 'barangay' => 'Washington', 'facility_slug' => $this->beta->slug, 'facility_name' => 'Beta Snapshot Equipment', 'reservation_date' => '2026-10-13', 'start_time' => '19:00', 'end_time' => '20:30', 'status' => 'accepted', 'purpose' => 'Evening Assembly', 'hourly_rate_snapshot' => '0.00']);
        $this->booking(['facility_name' => 'Zeta Historic Resource', 'reservation_date' => '2026-10-07', 'status' => 'approved', 'purpose' => 'Historic Gathering']);
        $this->booking(['user_id' => $this->outsider->id, 'reservation_date' => '2026-10-14', 'purpose' => 'Private Outsider Workshop']);
        $this->booking(['user_id' => $this->outsider->id, 'facility_id' => $this->beta->id, 'barangay' => 'Washington', 'facility_slug' => $this->beta->slug, 'facility_name' => 'Beta Snapshot Equipment', 'reservation_date' => '2026-10-15', 'purpose' => 'Private Outsider Equipment']);

        // Payment records do not replace the reservation's historical presentation fields.
        $accepted->payment()->create(['amount' => '7.25', 'payment_status' => 'refunded']);
        $officialUse = OfficialUse::create(['barangay' => 'Taft', 'facility_id' => $this->alpha->id, 'date' => '2026-10-11', 'start_time' => '12:00', 'end_time' => '16:00', 'purpose' => 'Official Assembly', 'status' => 'conflict']);
        OfficialUseConflict::create(['official_use_id' => $officialUse->id, 'reservation_id' => $accepted->id, 'resolution' => 'awaiting_user_decision']);
        $this->actingAs($this->resident)->withSession(['_token' => str_repeat('a', 40)]);
    }

    public function test_resident_listing_is_an_ownership_scoped_eager_loaded_collection_across_barangays(): void
    {
        $response = $this->assertIds(self::DEFAULT_IDS)->assertViewIs('reservations.index')->assertViewHas('isAdmin', false);
        $rows = $response->viewData('reservations');
        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $rows);
        foreach ($rows as $row) {
            $this->assertSame($this->resident->id, $row->user_id);
            $this->assertTrue($row->relationLoaded('facility'));
            $this->assertTrue($row->relationLoaded('officialUseConflicts'));
            $this->assertFalse($row->relationLoaded('payment'));
        }
        $conflict = $rows->firstWhere('id', 3)->officialUseConflicts->sole();
        $this->assertTrue($conflict->relationLoaded('officialUse'));
        $this->assertSame('Official Assembly', $conflict->officialUse->purpose);
        $response->assertSee('Alpha Snapshot Court')->assertSee('Beta Snapshot Equipment')
            ->assertSee('Barangay Taft')->assertSee('Barangay Washington')
            ->assertDontSee('Private Outsider Workshop')->assertDontSee('Private Outsider Equipment');
    }

    public function test_legacy_facility_snapshots_and_null_relations_are_not_repaired_or_excluded(): void
    {
        $rows = $this->listing()->viewData('reservations');
        $this->assertSame('Washington', $rows->firstWhere('id', 3)->barangay);
        $this->assertSame('Taft', $rows->firstWhere('id', 3)->managingBarangay());
        foreach ([4 => 'Taft', 5 => 'Washington'] as $id => $barangay) {
            $row = $rows->firstWhere('id', $id);
            $this->assertNull($row->facility_id);
            $this->assertNull($row->facility);
            $this->assertSame($barangay, $row->managingBarangay());
            $this->assertNull($row->fresh()->facility_id);
        }
        $this->assertIds([4], ['reservation' => 4])->assertSee('Legacy Hall')->assertSee('Ends Oct 10, 2026');
        $this->assertIds([5], ['reservation' => 5])->assertSee('Legacy Hall')
            ->assertDontSee('href="'.route('facilities.show', 'missing-resource').'"', false)
            ->assertSee('data-modal-open="resident-details-5"', false);
    }

    public function test_deleted_facility_relationship_keeps_the_owners_historical_card(): void
    {
        $removed = $this->resource('Taft', 'Deleted Court', 'deleted-court');
        $booking = $this->booking(['facility_id' => $removed->id, 'facility_slug' => $removed->slug, 'facility_name' => 'Deleted Snapshot Court']);
        $removed->delete();
        $response = $this->assertIds([$booking->id], ['reservation' => $booking->id]);
        $row = $response->viewData('reservations')->sole();
        $this->assertNull($row->facility_id);
        $this->assertNull($row->facility);
        $response->assertSee('Deleted Snapshot Court')->assertSee('Barangay Taft');
    }

    public function test_id_selection_preserves_integer_casts_and_never_exposes_another_residents_record(): void
    {
        foreach ([
            [1, [1]], ['01', [1]], ['1tail', [1]], ['1.9', [1]],
            ['#1', []], ['invalid', []], ['0', []], [-1, []], [99999, []],
            [[9], [1]], [[], []], [9, []], ['', self::DEFAULT_IDS],
        ] as [$reservation, $expected]) {
            // Use an explicit query for [] because http_build_query omits empty arrays.
            if ($reservation === []) {
                $request = Request::create(route('reservations.index'), 'GET', ['reservation' => []]);
                $request->setUserResolver(fn () => $this->resident);
                $this->assertSame([], (new ReservationPageController)->index($request)->getData()['reservations']->modelKeys());
            } else {
                $this->assertIds($expected, ['reservation' => $reservation]);
            }
        }
        $this->assertIds([], ['reservation' => 1, 'search' => 'Assembly']);
        $this->assertIds([], ['reservation' => 1, 'status' => 'accepted']);
        $this->actingAs($this->outsider);
        $this->assertIds([], ['reservation' => 1]);
        $this->assertIds([9], ['reservation' => 9]);
    }

    public function test_text_search_uses_snapshot_facility_and_purpose_without_adding_id_or_current_name_search(): void
    {
        foreach ([
            ['  Community Workshop  ', [1]], ['Alpha Snapshot Court', [6, 3, 1]],
            ['Beta Snapshot Equipment', [7, 2]], ['Assembly', [7, 3, 2]],
            ['Legacy Hall', [4, 5]], ['Current Alpha Court', []], ['Renamed Equipment', []],
            ['#1', []], ['1', []], ['alpha-court', []], ['History Resident', []],
            ['0', [2]], ['%', self::DEFAULT_IDS], ['_', self::DEFAULT_IDS],
            ['   ', self::DEFAULT_IDS], [str_repeat('z', 201), []], ['Private Outsider', []],
        ] as [$search, $expected]) {
            $response = $this->assertIds($expected, ['search' => $search]);
            $this->assertSame(trim($search), $response->viewData('selectedSearch'));
        }
    }

    public function test_existing_statuses_filter_exactly_and_unknown_or_array_statuses_are_ignored(): void
    {
        foreach ([
            ['pending', [6, 1]], ['accepted', [7, 3, 2]], ['rejected', [5]], ['cancelled', [4]],
            ['all', self::DEFAULT_IDS], ['approved', self::DEFAULT_IDS], ['Booked', self::DEFAULT_IDS],
            ['PENDING', self::DEFAULT_IDS], ['unknown', self::DEFAULT_IDS],
            [['pending'], self::DEFAULT_IDS], ['', self::DEFAULT_IDS],
        ] as [$status, $expected]) {
            $this->assertIds($expected, ['status' => $status]);
        }
    }

    public function test_sorts_and_direction_inputs_preserve_existing_order_and_fallbacks(): void
    {
        foreach ([
            'date' => self::DEFAULT_IDS,
            'facility' => [6, 3, 1, 7, 2, 4, 5, 8],
            // Equal status/date rows retain the database's existing tie behavior; there is no ID sort.
            'status' => [7, 2, 3, 8, 4, 6, 1, 5],
        ] as $sort => $expected) {
            foreach (['asc', 'desc', 'invalid', ''] as $direction) {
                $this->assertIds($expected, ['sort' => $sort, 'direction' => $direction]);
            }
        }
        foreach (['unknown', 'id', 'resource', 'date; DROP TABLE users', '', ['facility']] as $sort) {
            $this->assertIds(self::DEFAULT_IDS, ['sort' => $sort, 'direction' => 'asc']);
        }
        $this->assertIds(self::DEFAULT_IDS, ['direction' => ['asc']]);
    }

    public function test_empty_and_explicit_null_values_keep_the_current_view_defaults(): void
    {
        $response = $this->listing(['status' => '', 'sort' => '', 'search' => '', 'date_range' => '', 'from_date' => '', 'to_date' => '', 'reservation' => '']);
        foreach (['selectedStatus' => 'all', 'selectedSort' => 'date', 'selectedSearch' => '', 'selectedDateRange' => 'all', 'selectedFromDate' => null, 'selectedToDate' => null, 'isAdmin' => false] as $key => $value) {
            $this->assertSame($value, $response->viewData($key));
        }
        $request = Request::create(route('reservations.index'), 'GET', array_fill_keys(['status', 'sort', 'search', 'date_range', 'from_date', 'to_date', 'reservation'], null));
        $request->setUserResolver(fn () => $this->resident);
        $view = (new ReservationPageController)->index($request);
        $this->assertSame('reservations.index', $view->name());
        $this->assertSame(self::DEFAULT_IDS, $view->getData()['reservations']->modelKeys());
        foreach (['selectedStatus' => 'all', 'selectedSort' => 'date', 'selectedSearch' => '', 'selectedDateRange' => 'all', 'selectedFromDate' => null, 'selectedToDate' => null, 'isAdmin' => false] as $key => $value) {
            $this->assertSame($value, $view->getData()[$key]);
        }
    }

    public function test_resident_admin_only_filters_and_date_bounds_are_ignored_without_validation(): void
    {
        foreach ([
            ['date_range' => 'today'], ['date_range' => 'week'], ['date_range' => 'month'],
            ['from_date' => '2030-01-01', 'to_date' => '2000-01-01'],
            ['from_date' => 'invalid', 'to_date' => 'invalid', 'per_page' => 999, 'page' => -1],
            ['conflict' => 'official_use'], ['conflict' => 'none'],
            ['facility_id' => $this->alpha->id, 'barangay' => 'Taft', 'user_id' => $this->outsider->id],
            ['per_page' => [10], 'page' => ['bad'], 'from_date' => ['bad'], 'to_date' => ['bad']],
        ] as $filters) {
            $this->assertIds(self::DEFAULT_IDS, $filters);
        }
    }

    public function test_resident_results_are_never_paginated_even_with_page_or_size_parameters(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->booking(['reservation_date' => '2026-10-20', 'purpose' => 'Bulk History '.$i]);
        }
        foreach ([[], ['per_page' => 10, 'page' => 2], ['per_page' => 25, 'page' => 999], ['per_page' => 0, 'page' => -1]] as $filters) {
            $response = $this->listing($filters);
            $this->assertInstanceOf(Collection::class, $response->viewData('reservations'));
            $this->assertCount(31, $response->viewData('reservations'));
            $response->assertDontSee('data-reservation-table', false)->assertDontSee('reservation-datatable.js');
        }
    }

    public function test_existing_status_payment_and_conflict_presentation_uses_reservation_snapshots(): void
    {
        $this->assertIds([1], ['reservation' => 1])->assertSee('Pending')->assertSee('<dt>Hourly Rate</dt><dd>₱100.00 / hour</dd>', false)
            ->assertDontSee('Total Paid:')->assertDontSee('Total Payment:')->assertDontSee('Payment Status')->assertSee('Duration:');
        $this->assertIds([3], ['reservation' => 3])->assertSee('Booked')->assertSee('Total Payment: ₱150.00')->assertSee('Refunded')->assertDontSee('Total Paid:')
            ->assertSee('Duration: 2 hours')->assertDontSee('₱7.25')
            ->assertSee('Conflict: Official Use')->assertSee('Awaiting User Decision')
            ->assertSee('Request Reschedule')->assertSee('Request Cancellation');
        $this->assertIds([7], ['reservation' => 7])->assertSee('Booked')->assertSee('<dt>Hourly Rate</dt><dd>₱0.00 / hour</dd>', false)
            ->assertSee('Total Payment: Not recorded')->assertDontSee('Total Paid:')->assertSee('Duration: 1 hour 30 minutes');
        $this->assertIds([4], ['reservation' => 4])->assertSee('Cancelled')->assertSee('Cancellation Reason: Official Use')
            ->assertDontSee('Total Paid:')->assertSee('Ends Oct 10, 2026');
        $this->assertIds([5], ['reservation' => 5])->assertSee('Rejected')->assertSee('<dt>Hourly Rate</dt><dd>Not recorded</dd>', false);
        $this->assertIds([8], ['reservation' => 8])->assertSee('reservation-status-approved">Approved</span>', false)
            ->assertDontSee('reservation-status-accepted', false)->assertDontSee('Total Paid:');
    }

    public function test_card_labels_and_duration_formatting_preserve_stored_values_and_overnight_duration(): void
    {
        foreach ([
            ['10:00', '11:00', 60, '1 hour'],
            ['10:00', '12:00', 120, '2 hours'],
            ['10:00', '11:30', 90, '1 hour 30 minutes'],
            ['10:00', '10:45', 45, '45 minutes'],
            ['10:00', '10:01', 1, '1 minute'],
            ['10:00', '11:01', 61, '1 hour 1 minute'],
            ['23:30', '01:00', 90, '1 hour 30 minutes'],
        ] as [$start, $end, $minutes, $label]) {
            // Current resource ownership differs from the resident and the stored snapshot.
            $reservation = $this->booking(['barangay' => 'Washington', 'start_time' => $start, 'end_time' => $end]);
            $before = $reservation->fresh()->getAttributes();
            $this->assertIds([$reservation->id], ['reservation' => $reservation->id])
                ->assertSee('<span class="resident-reservation-id">Reservation #'.$reservation->id.'</span>', false)
                ->assertSee('<p>Barangay Taft ', false)
                ->assertDontSee('Managing Barangay:')
                ->assertSee('<p>Duration: '.$label.'</p>', false)
                ->assertSee('<dt>Duration</dt><dd>'.$label.'</dd>', false)
                ->assertSee('data-modal-open="resident-details-'.$reservation->id.'"', false)
                ->assertSee('reservation-status-pending', false);
            $this->assertSame($minutes, $reservation->fresh()->durationMinutes());
            $this->assertSame($before, $reservation->fresh()->getAttributes());
        }
        Notification::assertNothingSent();
    }

    public function test_resident_listing_does_not_write_or_dispatch_notifications(): void
    {
        $before = [];
        foreach (['reservations', 'payments', 'official_uses', 'official_use_conflicts', 'notifications', 'users', 'facilities'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->listing();
        $this->listing(['search' => 'Assembly', 'status' => 'accepted', 'sort' => 'facility']);
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        Notification::assertNothingSent();
    }

    public function test_admin_and_super_admin_keep_existing_tenant_scopes_pagination_and_directions(): void
    {
        $admin = User::factory()->create(['name' => 'Listing Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'barangay' => 'Taft']);
        $super = User::factory()->create(['name' => 'Listing Super Admin', 'email' => 'super@example.test', 'role' => 'super_admin', 'barangay' => 'Washington']);
        $this->actingAs($admin);
        $response = $this->assertIds([1, 3, 4, 6, 8, 9], ['sort' => 'id', 'direction' => 'asc'])->assertViewHas('isAdmin', true);
        $this->assertInstanceOf(LengthAwarePaginator::class, $response->viewData('reservations'));
        $this->assertSame(6, $response->viewData('reservations')->total());
        $this->assertSame(10, $response->viewData('reservations')->perPage());
        $this->assertIds([9, 8, 6, 4, 3, 1], ['sort' => 'id', 'direction' => 'desc']);
        $this->assertIds([3], ['search' => '#3']);
        $this->assertIds([], ['search' => '#2']);
        $this->actingAs($super);
        $response = $this->assertIds(range(1, 10), ['sort' => 'id', 'direction' => 'asc'])->assertViewHas('isAdmin', true);
        $this->assertInstanceOf(LengthAwarePaginator::class, $response->viewData('reservations'));
        $this->assertSame(10, $response->viewData('reservations')->total());
        $this->assertIds([2], ['reservation' => 2]);
        $this->assertSame(1, $this->listing(['page' => 999])->viewData('reservations')->currentPage());
    }

    public function test_admin_validation_stays_separate_from_unvalidated_resident_filters(): void
    {
        $filters = ['from_date' => 'invalid', 'to_date' => 'invalid', 'per_page' => 999, 'page' => -1, 'sort' => ['date']];
        $this->assertIds(self::DEFAULT_IDS, $filters);
        foreach (['admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'barangay' => 'Taft']);
            $this->actingAs($user)->from(route('reservations.index'))->getJson(route('reservations.index', $filters))
                ->assertRedirect(route('reservations.index'))->assertSessionHasErrors(['from_date', 'to_date', 'per_page', 'page', 'sort']);
        }
    }

    public function test_array_search_keeps_existing_error_before_admin_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($admin)->withoutExceptionHandling();
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Array to string conversion');
        $this->get(route('reservations.index', ['search' => ['invalid']]));
    }

    public function test_authentication_verification_and_deactivation_precede_malformed_search(): void
    {
        $url = route('reservations.index', ['search' => ['invalid'], 'per_page' => 999]);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('login'));
        $unverified = User::factory()->unverified()->create(['role' => 'user', 'barangay' => 'Washington']);
        $this->actingAs($unverified)->get($url)->assertRedirect(route('verification.notice'));
        $inactive = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft', 'is_active' => false]);
        $this->actingAs($inactive)->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_other_non_admin_role_keeps_the_existing_own_reservation_branch(): void
    {
        $this->outsider->update(['role' => 'observer']);
        $this->actingAs($this->outsider);
        $this->assertIds([10, 9])->assertViewHas('isAdmin', false);
    }

    private function listing(array $filters = []): TestResponse
    {
        $captureDirectory = getenv('RESIDENT_LISTING_CAPTURE_DIR');
        if ($captureDirectory) {
            DB::flushQueryLog();
            DB::enableQueryLog();
        }
        try {
            $response = $this->get(route('reservations.index').($filters ? '?'.http_build_query($filters) : ''))->assertOk();
            if ($captureDirectory) {
                $rows = $response->viewData('reservations');
                $capture = [
                    'filters' => $filters,
                    'collection_type' => get_class($rows),
                    'rows' => $rows instanceof LengthAwarePaginator ? $rows->getCollection()->toArray() : $rows->toArray(),
                    'pagination' => $rows instanceof LengthAwarePaginator ? ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'page' => $rows->currentPage()] : null,
                    'view_data' => array_intersect_key($response->original->getData(), array_flip(['isAdmin', 'selectedStatus', 'selectedSort', 'selectedSearch', 'selectedDateRange', 'selectedFromDate', 'selectedToDate'])),
                    'queries' => array_map(fn ($query) => ['query' => $query['query'], 'bindings' => $query['bindings']], DB::getQueryLog()),
                    'html' => $response->getContent(),
                ];
                $name = $this->name().'-'.hash('sha256', $this->app['auth']->id().'|'.json_encode($filters)).'.json';
                file_put_contents($captureDirectory.'/'.$name, json_encode($capture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            return $response;
        } finally {
            if ($captureDirectory) {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
    }

    private function assertIds(array $expected, array $filters = []): TestResponse
    {
        $response = $this->listing($filters);
        $rows = $response->viewData('reservations');
        $this->assertSame($expected, $rows instanceof LengthAwarePaginator ? $rows->getCollection()->modelKeys() : $rows->modelKeys(), json_encode($filters));

        return $response;
    }

    private function resource(string $barangay, string $name, string $slug): Facility
    {
        return Facility::create(['barangay' => $barangay, 'name' => $name, 'slug' => $slug, 'category' => 'Facility', 'description' => $name, 'capacity' => 50, 'location' => $barangay, 'status' => 'Available', 'hourly_rate' => '100.00']);
    }

    private function booking(array $overrides = []): Reservation
    {
        return Reservation::create(array_merge(['user_id' => $this->resident->id, 'barangay' => 'Taft', 'facility_id' => $this->alpha->id, 'facility_slug' => $this->alpha->slug, 'facility_name' => 'Alpha Snapshot Court', 'category' => 'Facility', 'location' => 'Historic Location', 'reservation_date' => '2026-10-10', 'start_time' => '10:00', 'end_time' => '16:00', 'purpose' => 'Community Workshop', 'attendees' => 10, 'status' => 'pending', 'hourly_rate_snapshot' => '100.00', 'total_payment' => null], $overrides));
    }
}
