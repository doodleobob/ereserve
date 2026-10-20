<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResourceDetailRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private Facility $chairs;

    private Facility $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-20 10:15:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['barangay' => 'Taft']);
        $base = ['barangay' => 'Taft', 'description' => 'Community resource', 'location' => 'Taft',
            'capacity' => 50, 'status' => 'Available', 'hourly_rate' => 50, 'reservation_access' => 'residents_only'];
        $this->chairs = Facility::create([...$base, 'name' => 'Chairs', 'slug' => 'chairs', 'category' => 'Equipment', 'total_quantity' => 40]);
        $this->court = Facility::create([...$base, 'name' => 'Covered Court', 'slug' => 'covered-court', 'category' => 'Facility']);
        $this->actingAs($this->resident);
    }

    private function bookingQuery(): array
    {
        return ['barangay' => 'Taft', 'month' => '2026-10', 'date' => '2026-10-22',
            'start_time' => '23:00', 'end_time' => '03:00', 'end_next_day' => '1', 'quantity' => '4'];
    }

    private function schedule(): array
    {
        return ['reservation_date' => '2026-10-22', 'start_time' => '23:00', 'end_time' => '03:00',
            'end_next_day' => 1, 'quantity' => 4, 'attendees' => 10, 'purpose' => 'Community gathering'];
    }

    public function test_canonical_details_reuse_the_booking_view_and_enforce_the_actual_category(): void
    {
        foreach ([['equipment.show', $this->chairs], ['facilities.show', $this->court]] as [$route, $resource]) {
            $this->get(route($route, ['slug' => $resource->slug, ...$this->bookingQuery(), 'type' => 'all']))
                ->assertOk()->assertViewIs('facilities.show')->assertViewHas('facility', fn ($item) => $item['id'] === $resource->id)
                ->assertViewHas('calendarRoute', $route)->assertSee('Availability Calendar')->assertSee('Booking Summary')
                ->assertSee('data-unified-booking', false)->assertSee('name="'.($resource->isEquipment() ? 'quantity' : 'attendees').'"', false)
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_wrong_type_urls_permanently_redirect_once_and_preserve_all_query_parameters(): void
    {
        foreach ([['facilities.show', 'equipment.show', $this->chairs], ['equipment.show', 'facilities.show', $this->court]] as [$old, $canonical, $resource]) {
            // A query named slug must survive without overriding the destination path.
            $query = $this->bookingQuery() + ['slug' => 'not-the-resource', 'extra' => ['note' => 'Keep this & that']];
            $response = $this->get(route($old, $resource->slug).'?'.http_build_query($query))->assertStatus(301)
                ->assertHeader('Cache-Control', 'no-store, private');
            $destination = $response->headers->get('Location');
            $this->assertSame(parse_url(route($canonical, $resource->slug), PHP_URL_PATH), parse_url($destination, PHP_URL_PATH));
            parse_str(parse_url($destination, PHP_URL_QUERY), $redirected);
            $this->assertEquals($query, $redirected);
            $this->get($destination)->assertOk()->assertViewHas('facility', fn ($item) => $item['category'] === $resource->category);
        }
        $this->assertDatabaseCount('facilities', 2);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_missing_and_inaccessible_resources_never_redirect_or_disclose_a_location(): void
    {
        foreach (['facilities.show', 'equipment.show'] as $route) {
            $this->get(route($route, 'missing'))->assertNotFound()->assertHeaderMissing('Location');
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']));
        foreach (['facilities.show', 'equipment.show'] as $route) {
            foreach ([$this->chairs, $this->court] as $resource) {
                $this->get(route($route, $resource->slug))->assertNotFound()->assertHeaderMissing('Location')
                    ->assertDontSee('Community resource');
            }
        }
    }

    public function test_authorized_admin_roles_can_view_equipment_and_guests_still_require_login(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => $role === 'admin' ? 'Taft' : 'Washington']));
            $this->get(route('facilities.show', 'chairs'))->assertStatus(301)->assertRedirect(route('equipment.show', 'chairs'));
            $this->get(route('equipment.show', 'chairs'))->assertOk()->assertSee('Back to Equipment');
        }
        auth()->logout();
        $this->get(route('equipment.show', 'chairs'))->assertRedirect(route('login'));
    }

    public function test_resident_cross_barangay_visibility_and_booking_permissions_are_unchanged(): void
    {
        $this->actingAs(User::factory()->create(['barangay' => 'Washington']));
        // The existing catalog permits residents to browse other barangays, while
        // residents-only resources cannot be booked by an outside resident.
        $this->get(route('equipment.show', 'chairs'))->assertOk()->assertSee('Residents Only')->assertDontSee('Submit Reservation');
        $this->get(route('facilities.show', 'chairs'))->assertRedirect(route('equipment.show', 'chairs'));
        $this->postJson(route('reservations.store', 'chairs'), $this->schedule())->assertForbidden();
        $this->chairs->update(['reservation_access' => 'all_registered_users']);
        $this->get(route('equipment.show', 'chairs'))->assertOk()->assertSee('Submit Reservation');
        $this->postJson(route('reservations.store', 'chairs'), $this->schedule())->assertCreated();
        $this->assertSame('Taft', Reservation::sole()->barangay);
    }

    public function test_lists_reservations_and_calendar_links_use_canonical_details(): void
    {
        $this->get(route('equipment'))->assertOk()->assertSee('href="'.route('equipment.show', 'chairs').'"', false)->assertSee('View Equipment');
        $this->get(route('facilities'))->assertOk()->assertSee('href="'.route('facilities.show', 'covered-court').'"', false)->assertSee('View Facility');
        foreach ([$this->chairs, $this->court] as $resource) {
            $this->postJson(route('reservations.store', $resource->slug), $this->schedule())->assertCreated();
        }
        $this->get(route('reservations.index'))->assertOk()
            ->assertSee('href="'.route('equipment.show', 'chairs').'">View Equipment', false)
            ->assertSee('href="'.route('facilities.show', 'covered-court').'">View Facility', false);
        $page = $this->get(route('equipment.show', ['slug' => 'chairs', ...$this->bookingQuery()]))->assertOk()->getContent();
        $document = new \DOMDocument;
        $document->loadHTML($page, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//*[@id="availability"]//a[@href]');
        $this->assertGreaterThan(30, $links->length);
        foreach ($links as $link) {
            $this->assertSame('/equipment/chairs', parse_url($link->getAttribute('href'), PHP_URL_PATH));
        }
        $this->get(route('calendar', ['facility' => 'chairs', 'date' => '2026-10-22']))->assertOk()->assertViewHas('selectedFacility', fn ($item) => $item['id'] === $this->chairs->id);
    }

    public function test_dashboard_bookmarks_and_super_admin_activity_link_directly_to_equipment(): void
    {
        $this->get(route('dashboard', ['resource' => 'chairs', ...$this->bookingQuery()]))
            ->assertRedirect(route('equipment.show', ['slug' => 'chairs', ...$this->bookingQuery()]));
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->get(route('dashboard'))->assertOk()->assertViewHas('recentActivity', fn ($items) => $items->contains('url', route('equipment.show', 'chairs'))
            && $items->contains('url', route('facilities.show', 'covered-court')));
    }

    public function test_both_booking_submissions_redirect_to_the_correct_detail_and_keep_pricing(): void
    {
        foreach ([['equipment.show', $this->chairs], ['facilities.show', $this->court]] as [$route, $resource]) {
            $before = $resource->fresh()->getAttributes();
            $this->post(route('reservations.store', $resource->slug), $this->schedule())
                ->assertSessionHasNoErrors()->assertRedirect(route($route, ['slug' => $resource->slug, 'date' => '2026-10-22']));
            $reservation = Reservation::where('facility_id', $resource->id)->sole();
            $this->assertSame(240, $reservation->durationMinutes());
            $this->assertSame('200.00', $reservation->calculatedAmount());
            $this->assertSame('pending', $reservation->status);
            $this->assertSame($before, $resource->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_old_url_redirect_preserves_flashed_validation_input(): void
    {
        $old = route('facilities.show', ['slug' => 'chairs', ...$this->bookingQuery()]);
        $this->from($old)->post(route('reservations.store', 'chairs'), [...$this->schedule(), 'quantity' => 41])
            ->assertRedirect($old)->assertSessionHasErrors('quantity');
        // Carry the browser's session through both redirects. Without this cookie,
        // each test request starts a new session instead of reloading its JSON data.
        $this->withCookie(config('session.cookie'), session()->getId());
        $redirect = $this->get($old)->assertStatus(301)->assertSessionHasErrors('quantity')->assertSessionHasInput('quantity', 41);
        $this->get($redirect->headers->get('Location'))->assertOk()->assertSee('Community gathering')
            ->assertSee('Only 40 units')->assertSee('value="41"', false);
    }
}
