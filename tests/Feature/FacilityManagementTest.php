<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FacilityManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('public');
    }

    public function test_admin_can_upload_a_facility_photo_visible_to_their_barangay(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $resident = User::factory()->create(['barangay' => 'Washington']);
        $otherResident = User::factory()->create(['barangay' => 'Alegria']);

        $this->actingAs($admin)->post(route('facilities.store'), $this->facilityData([
            'photo' => UploadedFile::fake()->image('covered-court.jpg', 800, 500),
        ]))->assertRedirect(route('facilities'));

        $facility = Facility::query()->where('name', 'Covered Court')->firstOrFail();

        $this->assertSame('Washington', $facility->barangay);
        $this->assertNotNull($facility->photo_path);
        $this->assertStringStartsWith('facilities/', $facility->photo_path);
        Storage::disk('public')->assertExists($facility->photo_path);

        $residentResponse = $this->actingAs($resident)->get(route('facilities'));
        $residentResponse->assertOk();
        $residentResponse->assertSee('Photo of Covered Court');
        $residentResponse->assertSee('/storage/'.$facility->photo_path, false);

        $otherResponse = $this->actingAs($otherResident)->get(route('facilities'));
        $otherResponse->assertOk();
        $otherResponse->assertDontSee('Covered Court');
    }

    public function test_edit_preserves_or_replaces_the_existing_photo(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        Storage::disk('public')->put('facilities/original.jpg', 'original-photo');
        $facility = $this->facility(['photo_path' => 'facilities/original.jpg']);

        $this->actingAs($admin)->patch(
            route('facilities.update', $facility->slug),
            $this->facilityData(['name' => 'Covered Court Updated'])
        )->assertRedirect(route('facilities'));

        $this->assertSame('facilities/original.jpg', $facility->fresh()->photo_path);
        Storage::disk('public')->assertExists('facilities/original.jpg');

        $this->actingAs($admin)->patch(
            route('facilities.update', $facility->slug),
            $this->facilityData([
                'name' => 'Covered Court Updated',
                'photo' => UploadedFile::fake()->image('replacement.png', 900, 600),
            ])
        )->assertRedirect(route('facilities'));

        $newPhotoPath = $facility->fresh()->photo_path;

        $this->assertNotSame('facilities/original.jpg', $newPhotoPath);
        Storage::disk('public')->assertMissing('facilities/original.jpg');
        Storage::disk('public')->assertExists($newPhotoPath);

        $response = $this->actingAs($admin)->get(route('facilities'));
        $response->assertSee('Current photo of Covered Court Updated');
        $response->assertSee('/storage/'.$newPhotoPath, false);
    }

    public function test_photo_url_is_relative_to_the_active_application_origin(): void
    {
        Storage::disk('public')->put('facilities/request-origin.jpg', 'photo-content');
        $facility = $this->facility(['photo_path' => 'facilities/request-origin.jpg']);
        $resident = User::factory()->create(['barangay' => 'Washington']);

        $response = $this->actingAs($resident)->get('/facilities');

        $response->assertOk();
        $response->assertSee('/storage/'.$facility->photo_path, false);
        $response->assertDontSee('http://localhost/storage/'.$facility->photo_path, false);
    }

    public function test_missing_photo_file_uses_the_existing_svg_fallback(): void
    {
        $this->facility(['photo_path' => 'facilities/missing.jpg']);
        $resident = User::factory()->create(['barangay' => 'Washington']);

        $response = $this->actingAs($resident)->get(route('facilities'));

        $response->assertOk();
        $response->assertDontSee('Photo of Covered Court');
        $response->assertDontSee('/storage/facilities/missing.jpg', false);
        $response->assertSee('<svg viewBox="0 0 24 24" aria-hidden="true">', false);
    }

    public function test_invalid_photo_is_rejected_without_creating_a_facility(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);

        $response = $this->actingAs($admin)->post(route('facilities.store'), $this->facilityData([
            'photo' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ]));

        $response->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('facilities', 0);
    }

    public function test_admin_unavailable_overrides_calendar_and_blocks_direct_reservation_posts(): void
    {
        $facility = $this->facility(['status' => 'Unavailable']);
        $resident = User::factory()->create(['barangay' => 'Washington']);
        $date = now()->addDays(5)->toDateString();

        $dashboardResponse = $this->actingAs($resident)->get(route('dashboard', [
            'facility' => $facility->slug,
            'month' => now()->addDays(5)->format('Y-m'),
            'date' => $date,
        ]));

        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('availability-badge-unavailable', false);
        $dashboardResponse->assertSee('calendar-day-facility-unavailable', false);
        $dashboardResponse->assertSee('schedule-slot-facility-unavailable', false);
        $dashboardResponse->assertSee('legend-facility-unavailable', false);
        $dashboardResponse->assertSee('This facility is currently unavailable for reservations.');
        $dashboardResponse->assertDontSee('Submit Reservation');

        $showResponse = $this->actingAs($resident)->get(route('facilities.show', $facility->slug));
        $showResponse->assertOk();
        $showResponse->assertSee('availability-badge-unavailable', false);
        $showResponse->assertDontSee('Submit Reservation');

        $reservationResponse = $this->actingAs($resident)
            ->from(route('facilities.show', $facility->slug))
            ->post(route('reservations.store', $facility->slug), [
                'reservation_date' => $date,
                'start_time' => '08:00',
                'end_time' => '09:00',
                'purpose' => 'Bypass attempt',
                'attendees' => 10,
            ]);

        $reservationResponse->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_reenabling_facility_restores_booking_calendar_and_overlap_requests(): void
    {
        $facility = $this->facility(['status' => 'Unavailable']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $userA = User::factory()->create(['barangay' => 'Washington']);
        $userB = User::factory()->create(['barangay' => 'Washington']);
        $date = now()->addDays(6)->toDateString();

        Reservation::create([
            'user_id' => $userA->id,
            'barangay' => 'Washington',
            'facility_id' => $facility->id,
            'facility_slug' => $facility->slug,
            'facility_name' => $facility->name,
            'category' => $facility->category,
            'location' => $facility->location,
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Accepted activity',
            'attendees' => 20,
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->patch(
            route('facilities.update', $facility->slug),
            $this->facilityData(['status' => 'Available'])
        )->assertRedirect(route('facilities'));

        $response = $this->actingAs($userB)->get(route('dashboard', [
            'facility' => $facility->slug,
            'month' => now()->addDays(6)->format('Y-m'),
            'date' => $date,
        ]));

        $response->assertOk();
        $response->assertSee('Partially Booked');
        $response->assertSee('schedule-slot-booked', false);
        $response->assertDontSee('calendar-day-facility-unavailable', false);

        $this->actingAs($userB)->post(route('reservations.store', $facility->slug), [
            'reservation_date' => $date,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Overlapping request',
            'attendees' => 10,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reservations', [
            'user_id' => $userB->id,
            'facility_id' => $facility->id,
            'status' => 'pending',
        ]);
    }

    private function facility(array $overrides = []): Facility
    {
        return Facility::create(array_merge([
            'barangay' => 'Washington',
            'slug' => 'covered-court',
            'name' => 'Covered Court',
            'category' => 'Facility',
            'description' => 'Covered court for community activities.',
            'capacity' => 100,
            'location' => 'Barangay Washington',
            'status' => 'Available',
        ], $overrides));
    }

    private function facilityData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Covered Court',
            'category' => 'Facility',
            'description' => 'Covered court for community activities.',
            'location' => 'Barangay Washington',
            'capacity' => 100,
            'status' => 'Available',
        ], $overrides);
    }
}
