<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\FacilityPhoto;
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
            'photos' => [UploadedFile::fake()->image('covered-court.jpg', 800, 500)],
        ]))->assertRedirect(route('facilities'));

        $facility = Facility::query()->where('name', 'Covered Court')->firstOrFail();

        $this->assertSame('Washington', $facility->barangay);
        $this->assertNotNull($facility->photo_path);
        $this->assertStringStartsWith('facilities/', $facility->photo_path);
        Storage::disk('public')->assertExists($facility->photo_path);
        $this->assertDatabaseCount('facility_photos', 1);

        $residentResponse = $this->actingAs($resident)->get(route('facilities'));
        $residentResponse->assertOk();
        $residentResponse->assertSee('Photo of Covered Court');
        $residentResponse->assertSee('/storage/'.$facility->photo_path, false);

        $showResponse = $this->actingAs($resident)->get(route('facilities.show', $facility->slug));
        $showResponse->assertOk();
        $showResponse->assertDontSee('class="facility-carousel-control facility-carousel-next"', false);

        $otherResponse = $this->actingAs($otherResident)->get(route('facilities'));
        $otherResponse->assertOk();
        $otherResponse->assertDontSee('Covered Court');
    }

    public function test_four_photos_are_stored_and_rendered_as_a_carousel(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $resident = User::factory()->create(['barangay' => 'Washington']);

        $this->actingAs($admin)->post(route('facilities.store'), $this->facilityData([
            'photos' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.png'),
                UploadedFile::fake()->image('four.webp'),
            ],
        ]))->assertRedirect(route('facilities'));

        $facility = Facility::query()->where('name', 'Covered Court')->firstOrFail();
        $photos = $facility->photos()->get();

        $this->assertCount(4, $photos);
        $this->assertSame($photos->first()->path, $facility->photo_path);
        $photos->each(fn (FacilityPhoto $photo) => Storage::disk('public')->assertExists($photo->path));

        $response = $this->actingAs($resident)->get(route('facilities.show', $facility->slug));
        $response->assertOk();
        $response->assertSee('data-facility-carousel', false);
        $response->assertSee('data-carousel-previous', false);
        $response->assertSee('data-carousel-next', false);
        $response->assertSee('data-carousel-indicator="3"', false);
        foreach ($photos as $photo) {
            $response->assertSee('/storage/'.$photo->path, false);
        }
    }

    public function test_more_than_four_photos_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);

        $response = $this->actingAs($admin)->post(route('facilities.store'), $this->facilityData([
            'photos' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.jpg'),
                UploadedFile::fake()->image('four.jpg'),
                UploadedFile::fake()->image('five.jpg'),
            ],
        ]));

        $response->assertSessionHasErrors('photos');
        $this->assertDatabaseCount('facilities', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('facilities'));
    }

    public function test_edit_preserves_photos_and_can_remove_and_add_up_to_four(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $facility = $this->facility();

        foreach (range(0, 3) as $index) {
            $path = "facilities/original-{$index}.jpg";
            Storage::disk('public')->put($path, "original-photo-{$index}");
            $facility->photos()->create(['path' => $path, 'sort_order' => $index]);
        }
        $facility->update(['photo_path' => 'facilities/original-0.jpg']);

        $this->actingAs($admin)->patch(
            route('facilities.update', $facility->slug),
            $this->facilityData(['name' => 'Covered Court Updated'])
        )->assertRedirect(route('facilities'));

        $this->assertCount(4, $facility->fresh()->photos);
        Storage::disk('public')->assertExists('facilities/original-0.jpg');

        $removedPhoto = $facility->photos()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($admin)->patch(
            route('facilities.update', $facility->slug),
            $this->facilityData([
                'name' => 'Covered Court Updated',
                'remove_photo_ids' => [$removedPhoto->id],
                'photos' => [UploadedFile::fake()->image('replacement.png', 900, 600)],
            ])
        )->assertRedirect(route('facilities'));

        $facility->refresh()->load('photos');
        $newPhotoPath = $facility->photos->last()->path;

        $this->assertCount(4, $facility->photos);
        $this->assertSame('facilities/original-1.jpg', $facility->photo_path);
        Storage::disk('public')->assertMissing($removedPhoto->path);
        Storage::disk('public')->assertExists($newPhotoPath);

        $response = $this->actingAs($admin)->get(route('facilities'));
        $response->assertSee('Current photo 1 of Covered Court Updated');
        $response->assertSee('/storage/'.$facility->photo_path, false);
    }

    public function test_edit_rejects_a_new_photo_when_four_are_retained(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $facility = $this->facility();

        foreach (range(0, 3) as $index) {
            $path = "facilities/retained-{$index}.jpg";
            Storage::disk('public')->put($path, "retained-photo-{$index}");
            $facility->photos()->create(['path' => $path, 'sort_order' => $index]);
        }
        $facility->update(['photo_path' => 'facilities/retained-0.jpg']);

        $response = $this->actingAs($admin)->patch(
            route('facilities.update', $facility->slug),
            $this->facilityData([
                'photos' => [UploadedFile::fake()->image('too-many.jpg')],
            ])
        );

        $response->assertSessionHasErrors('photos');
        $this->assertCount(4, $facility->fresh()->photos);
        $this->assertSame([], array_values(array_filter(
            Storage::disk('public')->allFiles('facilities'),
            fn (string $path) => ! str_contains($path, 'retained-')
        )));
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
            'photos' => [UploadedFile::fake()->create('notes.txt', 20, 'text/plain')],
        ]));

        $response->assertSessionHasErrors('photos.0');
        $this->assertDatabaseCount('facilities', 0);
    }

    public function test_admin_cannot_remove_a_photo_from_another_barangay(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $ownFacility = $this->facility();
        $otherFacility = $this->facility([
            'barangay' => 'Alegria',
            'slug' => 'alegria-hall',
            'name' => 'Alegria Hall',
            'location' => 'Barangay Alegria',
        ]);
        Storage::disk('public')->put('facilities/alegria.jpg', 'other-photo');
        $otherPhoto = $otherFacility->photos()->create([
            'path' => 'facilities/alegria.jpg',
            'sort_order' => 0,
        ]);
        $otherFacility->update(['photo_path' => $otherPhoto->path]);

        $response = $this->actingAs($admin)->patch(
            route('facilities.update', $ownFacility->slug),
            $this->facilityData(['remove_photo_ids' => [$otherPhoto->id]])
        );

        $response->assertSessionHasErrors('remove_photo_ids');
        $this->assertDatabaseHas('facility_photos', ['id' => $otherPhoto->id]);
        Storage::disk('public')->assertExists($otherPhoto->path);
    }

    public function test_deleting_a_facility_deletes_its_gallery_records_and_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $facility = $this->facility();
        Storage::disk('public')->put('facilities/delete-me.jpg', 'photo');
        $photo = $facility->photos()->create([
            'path' => 'facilities/delete-me.jpg',
            'sort_order' => 0,
        ]);
        $facility->update(['photo_path' => $photo->path]);

        $this->actingAs($admin)
            ->delete(route('facilities.destroy', $facility->slug))
            ->assertRedirect(route('facilities'));

        $this->assertDatabaseMissing('facilities', ['id' => $facility->id]);
        $this->assertDatabaseMissing('facility_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($photo->path);
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
