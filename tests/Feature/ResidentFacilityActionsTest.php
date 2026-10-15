<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResidentFacilityActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private Facility $hall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));
        Notification::fake();
        Storage::fake('public');
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $this->hall = $this->resource();
        $this->actingAs($this->resident);
    }

    private function resource(array $overrides = []): Facility
    {
        return Facility::create(array_merge([
            'name' => 'Community Hall', 'slug' => 'community-hall', 'barangay' => 'Taft',
            'category' => 'Facility', 'description' => str_repeat('Full resident description. ', 15),
            'location' => 'Function Room A', 'capacity' => 15, 'status' => 'Available',
            'hourly_rate' => '1234.50', 'reservation_access' => 'all_registered_users',
        ], $overrides));
    }

    private function dialog(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<dialog\b[^>]*id="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/dialog>/s', $html, $matches));

        return $matches[1];
    }

    private function booking(array $overrides = []): array
    {
        return array_merge(['reservation_date' => '2026-10-10', 'start_time' => '13:00', 'end_time' => '14:00', 'purpose' => 'Community event', 'attendees' => 10], $overrides);
    }

    public function test_resident_cards_link_to_dedicated_details_with_the_existing_gallery(): void
    {
        $other = $this->resource(['name' => 'Meeting Room', 'slug' => 'meeting-room']);
        foreach ([[800, 400], [300, 600]] as $index => [$width, $height]) {
            $path = UploadedFile::fake()->image('photo-'.$index.'.png', $width, $height)->store('facilities', 'public');
            $this->hall->photos()->create(['path' => $path, 'sort_order' => $index]);
        }
        $this->get(route('facilities'))->assertOk()->assertSee('View Facility')
            ->assertSee(route('facilities.show', $this->hall->slug))->assertSee(route('facilities.show', $other->slug))
            ->assertDontSee('reserve-facility-')->assertDontSee('view-facility-');
        $details = $this->get(route('facilities.show', $this->hall->slug))->assertOk()->getContent();
        foreach ([$this->hall->name, trim($this->hall->description), 'Taft', 'All Registered Users', 'Available', 'Function Room A', '15 persons'] as $value) {
            $this->assertStringContainsString($value, $details);
        }
        $this->assertStringNotContainsString($other->name, $details);
        $this->assertSame(2, substr_count($details, 'data-carousel-slide'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_details_use_the_selected_form_and_existing_endpoint(): void
    {
        $page = $this->get(route('facilities.show', ['slug' => $this->hall->slug, 'date' => '2026-10-10', 'start_time' => '13:17', 'end_time' => '14:23']))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('reservations.store', $this->hall->slug).'"', $page);
        $this->assertStringNotContainsString('<select', $page);
        foreach (['reservation_date', 'start_time', 'end_time', 'purpose', 'attendees'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $page);
        }
        $this->assertSame(1, substr_count($page, 'Submit Reservation'));
        $this->assertStringContainsString('name="start_time" value="13:17"', $page);
    }

    public function test_unavailable_and_restricted_facilities_keep_details_but_cannot_reserve(): void
    {
        $this->hall->update(['status' => 'Unavailable']);
        $restricted = $this->resource(['name' => 'Washington Hall', 'slug' => 'washington-hall', 'barangay' => 'Washington', 'reservation_access' => 'residents_only']);
        foreach ([$this->hall, $restricted] as $facility) {
            $this->get(route('facilities', ['barangay' => $facility->barangay]))->assertOk()->assertSee(route('facilities.show', $facility->slug))->assertDontSee('reserve-facility-');
            $this->get(route('facilities.show', $facility->slug))->assertOk()->assertDontSee('Submit Reservation');
        }
        $this->postJson(route('reservations.store', $this->hall->slug), $this->booking())->assertUnprocessable()->assertJsonValidationErrors('reservation');
        $this->postJson(route('reservations.store', $restricted->slug), $this->booking())->assertForbidden();
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_modal_submission_uses_existing_creation_rules_snapshot_and_duplicate_guard(): void
    {
        $this->postJson(route('reservations.store', $this->hall->slug), $this->booking())
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('message', 'Reservation request submitted successfully.');
        $this->assertDatabaseHas('reservations', ['user_id' => $this->resident->id, 'facility_id' => $this->hall->id, 'facility_slug' => $this->hall->slug, 'barangay' => 'Taft', 'hourly_rate_snapshot' => '1234.50', 'status' => 'pending']);
        $this->postJson(route('reservations.store', $this->hall->slug), $this->booking(['reservation_date' => '2026-10-11']))
            ->assertUnprocessable()->assertJsonValidationErrors('reservation');
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_regular_form_submission_keeps_its_existing_redirect_and_flash(): void
    {
        $this->post(route('reservations.store', $this->hall->slug), $this->booking())
            ->assertRedirect(route('facilities.show', ['slug' => $this->hall->slug, 'date' => '2026-10-10']))
            ->assertSessionHas('reservation_status', 'Reservation request submitted successfully.');
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_modal_submission_preserves_validation_errors(): void
    {
        foreach ([['attendees' => 16], ['reservation_date' => '2026-10-03'], ['end_time' => '13:00'], ['purpose' => '']] as $invalid) {
            $this->postJson(route('reservations.store', $this->hall->slug), $this->booking($invalid))->assertUnprocessable()->assertJsonStructure(['errors']);
        }
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_both_response_formats_block_official_use_including_overnight_and_allow_adjacent_slots(): void
    {
        foreach (['active', 'conflict'] as $status) {
            OfficialUse::query()->delete();
            OfficialUse::create(['barangay' => 'Taft', 'facility_id' => $this->hall->id, 'date' => '2026-10-09', 'start_time' => '23:00', 'end_time' => '14:00', 'purpose' => 'Private official meeting', 'status' => $status, 'created_by' => $this->resident->id]);
            $this->postJson(route('reservations.store', $this->hall->slug), $this->booking())->assertUnprocessable()->assertJsonValidationErrors('reservation');
            $this->post(route('reservations.store', $this->hall->slug), $this->booking())->assertSessionHasErrors('reservation');
            $this->assertDatabaseCount('reservations', 0);
        }
        $this->postJson(route('reservations.store', $this->hall->slug), $this->booking(['start_time' => '14:00', 'end_time' => '15:00']))->assertCreated();
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_resident_details_expose_only_public_facility_and_availability_data(): void
    {
        $other = User::factory()->create(['barangay' => 'Taft', 'name' => 'Private Resident Name', 'email' => 'private-resident@example.test']);
        Reservation::create(['user_id' => $other->id, 'barangay' => 'Taft', 'facility_id' => $this->hall->id, 'facility_slug' => $this->hall->slug, 'facility_name' => $this->hall->name, 'category' => 'Facility', 'location' => 'Function Room A', 'reservation_date' => '2026-10-04', 'start_time' => '09:00', 'end_time' => '11:00', 'purpose' => 'Private reservation purpose', 'attendees' => 5, 'status' => 'accepted', 'hourly_rate_snapshot' => 100]);
        OfficialUse::create(['barangay' => 'Taft', 'facility_id' => $this->hall->id, 'date' => '2026-10-10', 'start_time' => '13:00', 'end_time' => '14:00', 'purpose' => 'Private official meeting', 'status' => 'active', 'created_by' => $other->id]);
        $this->get(route('facilities'))->assertOk()->assertSee('Currently in Use')
            ->assertDontSee($other->name)->assertDontSee($other->email)->assertDontSee('Private reservation purpose')->assertDontSee('Private official meeting')
            ->assertDontSee('Official Use ID')->assertDontSee('Reservation ID')->assertDontSee('data-modal-open="edit-facility-', false);
    }

    public function test_admin_and_super_admin_details_keep_close_only(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $html = $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => 'Taft']))->get(route('facilities'))->assertOk()->getContent();
            $details = $this->dialog($html, 'view-facility-'.$this->hall->slug);
            $this->assertStringNotContainsString('>Reserve</button>', $details);
            $this->assertStringNotContainsString('id="reserve-facility-', $html);
            $this->assertStringContainsString('data-modal-close>Close', $details);
        }
    }
}
