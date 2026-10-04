<?php

namespace Tests\Feature;

use App\Models\Facility;
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
            preg_match('~<div class="reservation-table-menu">(.*?)</div>~s', $response->getContent(), $menu);
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
