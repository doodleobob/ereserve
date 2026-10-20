<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\OfficialUseConflict;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationDataTableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $resident;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['barangay' => 'Washington']);
        $this->facility = Facility::create(['barangay' => 'Taft', 'slug' => 'taft-court', 'name' => 'Taft Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
    }

    private function booking(array $attributes = []): Reservation
    {
        return Reservation::create(array_merge(['user_id' => $this->resident->id, 'facility_id' => $this->facility->id, 'barangay' => 'Taft', 'facility_slug' => 'taft-court', 'facility_name' => 'Taft Court', 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => today()->addDay()->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00', 'purpose' => 'Community workshop', 'attendees' => 10, 'status' => 'pending', 'hourly_rate_snapshot' => 100], $attributes));
    }

    public function test_table_action_matrix_modals_and_resident_layout(): void
    {
        $r = $this->booking();
        $this->actingAs($this->admin);
        foreach (['pending' => ['View', 'Accept', 'Reject'], 'accepted' => ['View', 'Edit'], 'rejected' => ['View'], 'cancelled' => ['View']] as $status => $actions) {
            $r->update(['status' => $status]);
            $response = $this->get(route('reservations.index'))->assertOk()->assertSee('reservation-datatable')->assertSee('Reservation ID')->assertSee('Reservation Date');
            preg_match('~<div class="reservation-table-menu"[^>]*>(.*?)</div>~s', $response->getContent(), $menu);
            preg_match_all('~<button[^>]*>(.*?)</button>~s', $menu[1], $buttons);
            $this->assertSame($actions, array_map('trim', $buttons[1]));
            $response->assertSee('id="view-'.$r->id.'"', false);
            if ($status === 'accepted') {
                $response->assertSee('id="edit-'.$r->id.'"', false)->assertSee('Reschedule Reservation')->assertSee('Cancel Reservation')->assertSee('data-edit-step="choose"', false);
            } else {
                $response->assertDontSee('id="edit-'.$r->id.'"', false);
            }
            if ($status === 'pending') {
                $response->assertSee('id="reject-'.$r->id.'"', false)->assertSee('id="payment-confirmation"', false);
            } else {
                $response->assertDontSee('id="reject-'.$r->id.'"', false);
            }
        }
        $this->actingAs($this->resident)->get(route('reservations.index'))->assertOk()->assertSee('reservation-list-card')->assertDontSee('data-reservation-table')->assertDontSee('reservation-datatable.js');
    }

    public function test_search_filters_sorting_and_rows_per_page_preserve_query(): void
    {
        $first = $this->booking(['status' => 'accepted', 'total_payment' => 10]);
        for ($i = 0; $i < 25; $i++) {
            $this->booking(['status' => 'accepted', 'total_payment' => 100 + $i]);
        }
        $this->booking(['status' => 'cancelled']);
        $this->actingAs($this->admin);
        foreach (['#'.$first->id, $this->resident->name, 'Taft Court', 'workshop'] as $search) {
            $this->get(route('reservations.index', ['search' => $search]))->assertViewHas('reservations', fn ($rows) => $rows->total() === (str_starts_with($search, '#') ? 1 : 27));
        }
        $query = ['search' => 'Taft', 'status' => 'accepted', 'from_date' => today()->toDateString(), 'to_date' => today()->addDays(2)->toDateString(), 'sort' => 'amount', 'direction' => 'asc', 'per_page' => 10];
        $response = $this->get(route('reservations.index', $query))->assertViewHas('reservations', fn ($rows) => $rows->total() === 26 && $rows->count() === 10 && $rows->first()->id === $first->id);
        parse_str(parse_url($response->viewData('reservations')->nextPageUrl(), PHP_URL_QUERY), $next);
        foreach ($query as $key => $value) {
            $this->assertEquals($value, $next[$key]);
        }
        foreach ([10, 25, 50, 100] as $size) {
            $this->get(route('reservations.index', ['per_page' => $size]))->assertViewHas('reservations', fn ($rows) => $rows->perPage() === $size && $rows->count() === min($size, 27));
        }
        $this->get(route('reservations.index', ['status' => 'cancelled']))->assertViewHas('reservations', fn ($rows) => $rows->total() === 1);
        $this->get(route('reservations.index', ['from_date' => today()->addDays(2)->toDateString()]))->assertViewHas('reservations', fn ($rows) => $rows->isEmpty());
        $this->get(route('reservations.index', ['sort' => 'id', 'direction' => 'desc']))->assertViewHas('reservations', fn ($rows) => $rows->first()->id !== $first->id);
        $this->get(route('reservations.index', ['sort' => 'id; DROP TABLE users', 'direction' => 'anything']))->assertOk();
        $this->get(route('reservations.index', ['per_page' => 1000]))->assertSessionHasErrors('per_page');
        $this->get(route('reservations.index', ['per_page' => '']))->assertOk()->assertViewHas('reservations', fn ($rows) => $rows->perPage() === 10);
        $this->get(route('reservations.index', ['per_page' => [100]]))->assertSessionHasErrors('per_page');
        $this->get(route('reservations.index', ['sort' => ['id']]))->assertSessionHasErrors('sort');
        $this->get(route('reservations.index', ['from_date' => 'not-a-date']))->assertSessionHasErrors('from_date');
    }

    public function test_scoping_json_actions_payment_compatibility_and_invalid_transitions(): void
    {
        $r = $this->booking();
        $other = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($other)->get(route('reservations.index'))->assertViewHas('reservations', fn ($rows) => $rows->total() === 0);
        foreach (['accept', 'reject'] as $action) {
            $this->postJson(route('reservations.'.$action, $r), ['total_payment' => 200, 'payment_confirmed' => 1, 'rejection_confirmed' => 1])->assertForbidden();
        }
        $this->patchJson(route('reservations.edit-accepted', $r), ['total_payment' => 300])->assertForbidden();
        $this->actingAs($this->resident)->postJson(route('reservations.accept', $r), ['total_payment' => 200, 'payment_confirmed' => 1])->assertForbidden();
        $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $r), ['total_payment' => 200])->assertUnprocessable();
        $this->postJson(route('reservations.accept', $r), ['total_payment' => 200])->assertUnprocessable();
        $this->postJson(route('reservations.accept', $r), ['total_payment' => 200, 'payment_confirmed' => 1])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame('200.00', $r->fresh()->total_payment);
        $this->assertSame(1, $this->resident->notifications()->count());
        $this->postJson(route('reservations.reject', $r), ['rejection_confirmed' => 1])->assertUnprocessable();
        $this->postJson(route('reservations.accept', $r), ['total_payment' => 999, 'payment_confirmed' => 1])->assertUnprocessable();
        $this->patchJson(route('reservations.edit-accepted', $r), ['action' => 'reschedule', 'reservation_date' => today()->addDays(2)->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00'])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame('200.00', $r->fresh()->total_payment);
        $this->assertSame(2, $this->resident->notifications()->count());
    }

    public function test_date_presets_and_manual_bounds_keep_their_existing_intersection(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 12:00'));
        $dates = ['2026-10-19', '2026-10-20', '2026-10-25', '2026-10-26', '2026-10-31', '2026-11-01'];
        foreach ($dates as $date) {
            $this->booking(['reservation_date' => $date]);
        }
        $this->actingAs($this->admin);
        $cases = [
            [['date_range' => 'all'], $dates],
            [['date_range' => 'today'], ['2026-10-20']],
            [['date_range' => 'week'], array_slice($dates, 0, 3)],
            [['date_range' => 'month'], array_slice($dates, 0, 5)],
            [['from_date' => '2026-10-25'], array_slice($dates, 2)],
            [['to_date' => '2026-10-20'], array_slice($dates, 0, 2)],
            [['from_date' => '2026-10-20', 'to_date' => '2026-10-25'], array_slice($dates, 1, 2)],
            [['date_range' => 'week', 'from_date' => '2026-10-20', 'to_date' => '2026-10-25'], array_slice($dates, 1, 2)],
            [['date_range' => 'today', 'from_date' => '2026-10-26'], []],
        ];
        foreach ($cases as [$query, $expected]) {
            $response = $this->get(route('reservations.index', $query + ['sort' => 'date', 'direction' => 'asc']))->assertOk();
            $this->assertSame($expected, $response->viewData('reservations')->pluck('reservation_date')->all());
            $response->assertViewHas('selectedDateRange', $query['date_range'] ?? 'all')
                ->assertViewHas('selectedFromDate', $query['from_date'] ?? null)
                ->assertViewHas('selectedToDate', $query['to_date'] ?? null);
            if ($expected === []) {
                $response->assertSee('Showing 0 to 0 of 0 reservations')->assertSee('No reservations match the selected filters.');
            }
        }
    }

    public function test_combined_equipment_conflict_filters_persist_through_pagination_and_sorting(): void
    {
        $equipment = $this->facility->replicate();
        $equipment->fill(['slug' => 'taft-chairs', 'name' => 'Taft Chairs', 'category' => 'Equipment'])->save();
        $use = OfficialUse::create(['barangay' => 'Taft', 'facility_id' => $equipment->id, 'date' => '2026-10-20', 'start_time' => '13:00', 'end_time' => '15:00', 'purpose' => 'Assembly', 'status' => 'active', 'created_by' => $this->admin->id]);
        $attributes = ['facility_id' => $equipment->id, 'facility_slug' => $equipment->slug, 'facility_name' => $equipment->name, 'category' => 'Equipment', 'quantity' => 2, 'status' => 'accepted', 'reservation_date' => '2026-10-20'];
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $reservation = $this->booking($attributes);
            $ids[] = $reservation->id;
            OfficialUseConflict::create(['official_use_id' => $use->id, 'reservation_id' => $reservation->id, 'resolution' => 'awaiting_user_decision']);
        }
        $resolved = $this->booking($attributes);
        OfficialUseConflict::create(['official_use_id' => $use->id, 'reservation_id' => $resolved->id, 'resolution' => 'resolved']);
        $this->booking(array_merge($attributes, ['status' => 'pending']));
        $this->booking(['status' => 'accepted', 'reservation_date' => '2026-10-20']);
        $foreign = $this->booking(array_merge($attributes, ['facility_id' => null, 'barangay' => 'Washington']));
        OfficialUseConflict::create(['official_use_id' => $use->id, 'reservation_id' => $foreign->id, 'resolution' => 'awaiting_user_decision']);
        $this->actingAs($this->admin);
        $query = ['search' => 'workshop', 'status' => 'accepted', 'category' => 'Equipment', 'conflict' => 'official_use', 'from_date' => '2026-10-20', 'to_date' => '2026-10-20', 'sort' => 'id', 'direction' => 'asc', 'per_page' => 10];
        $response = $this->get(route('reservations.index', $query))->assertOk()->assertSee('Showing 1 to 10 of 12 reservations');
        $this->assertSame(array_slice($ids, 0, 10), $response->viewData('reservations')->modelKeys());
        $nextUrl = $response->viewData('reservations')->nextPageUrl();
        parse_str(parse_url($nextUrl, PHP_URL_QUERY), $next);
        foreach ($query as $key => $value) {
            $this->assertEquals($value, $next[$key]);
        }
        $nextResponse = $this->get($nextUrl)->assertOk()->assertSee('Showing 11 to 12 of 12 reservations');
        $this->assertSame(array_slice($ids, 10), $nextResponse->viewData('reservations')->modelKeys());
        $descending = $this->get(route('reservations.index', array_replace($query, ['direction' => 'desc'])))->assertOk();
        $this->assertSame(array_slice(array_reverse($ids), 0, 10), $descending->viewData('reservations')->modelKeys());
        foreach (['none' => 1, 'all' => 13] as $conflict => $count) {
            $this->get(route('reservations.index', array_replace($query, ['conflict' => $conflict])))
                ->assertOk()->assertViewHas('reservations', fn ($rows) => $rows->total() === $count);
        }
        $this->get(route('reservations.index', ['category' => 'Facility']))->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->total() === 1);
        $this->get(route('reservations.index', ['category' => 'Equipment', 'status' => 'pending']))->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->total() === 1);
        foreach (['#'.$ids[0], (string) $ids[0], $this->resident->name, 'Taft Chairs', 'workshop'] as $search) {
            $rows = $this->get(route('reservations.index', ['search' => $search, 'per_page' => 100]))->assertOk()->viewData('reservations');
            $this->assertContains($ids[0], $rows->modelKeys());
            $this->assertNotContains($foreign->id, $rows->modelKeys());
        }
        $this->get(route('reservations.index', ['search' => '#'.$foreign->id]))->assertOk()
            ->assertViewHas('reservations', fn ($rows) => $rows->isEmpty());
    }

    public function test_table_preserves_historical_minutes_and_explicit_overnight_end_date(): void
    {
        $sameDay = $this->booking(['reservation_date' => '2026-10-20', 'start_time' => '09:15', 'end_time' => '10:45']);
        $overnight = $this->booking(['reservation_date' => '2026-10-31', 'start_time' => '23:15', 'end_time' => '03:45']);
        $midnight = $this->booking(['reservation_date' => '2026-12-31', 'start_time' => '22:30', 'end_time' => '00:00']);
        foreach ([$sameDay, $overnight, $midnight] as $reservation) {
            $reservation->refresh();
        }
        $html = $this->actingAs($this->admin)->get(route('reservations.index'))->assertOk()->getContent();
        foreach ([
            [$sameDay, '9:15 AM', '10:45 AM', null],
            [$overnight, '11:15 PM', '3:45 AM', 'Ends Nov 1, 2026'],
            [$midnight, '10:30 PM', '12:00 AM', 'Ends Jan 1, 2027'],
        ] as [$reservation, $start, $end, $endDate]) {
            preg_match('~<tr data-reservation-id="'.$reservation->id.'">(.*?)</tr>~s', $html, $row);
            preg_match_all('~<td[^>]*>(.*?)</td>~s', $row[1], $cells);
            $this->assertCount(8, $cells[1]);
            $this->assertStringContainsString($start, $cells[1][4]);
            $this->assertStringContainsString($end, $cells[1][4]);
            if ($endDate) {
                $this->assertStringContainsString($endDate, $cells[1][4]);
            } else {
                $this->assertStringNotContainsString('Ends ', $cells[1][4]);
            }
            $this->assertSame($reservation->getRawOriginal(), $reservation->fresh()->getRawOriginal());
        }
    }

    public function test_rejection_confirmation_and_edit_guards_for_historical_records(): void
    {
        $r = $this->booking();
        $this->actingAs($this->admin)->postJson(route('reservations.reject', $r))->assertUnprocessable();
        $this->assertSame('pending', $r->fresh()->status);
        $this->postJson(route('reservations.reject', $r), ['rejection_confirmed' => 1])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertNull($r->fresh()->total_payment);
        foreach (['rejected', 'cancelled'] as $status) {
            $r->update(['status' => $status]);
            $this->patchJson(route('reservations.edit-accepted', $r), ['total_payment' => 100])->assertUnprocessable();
            $this->postJson(route('reservations.accept', $r), ['total_payment' => 100, 'payment_confirmed' => 1])->assertUnprocessable();
            $this->assertSame($status, $r->fresh()->status);
        }
        $this->get(route('reservations.index', ['page' => 999]))->assertViewHas('reservations', fn ($rows) => $rows->currentPage() === 1 && $rows->total() === 1);
    }

    public function test_rendered_browser_fixtures(): void
    {
        $r = $this->booking();
        $this->actingAs($this->admin);
        $save = function ($name, $response) {
            $response->assertOk();
            if ($directory = getenv('DATATABLE_RENDER_DIR')) {
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        };
        $save('pending', $this->get(route('reservations.index')));
        $this->postJson(route('reservations.accept', $r), ['total_payment' => 200, 'payment_confirmed' => 1])->assertOk();
        $save('accepted', $this->get(route('reservations.index')));
        $r->update(['status' => 'rejected']);
        $save('rejected', $this->get(route('reservations.index')));
        $r->update(['status' => 'cancelled']);
        $save('cancelled', $this->get(route('reservations.index')));
    }
}
