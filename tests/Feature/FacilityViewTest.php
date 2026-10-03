<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FacilityViewTest extends TestCase
{
    use RefreshDatabase;

    private function facility(array $overrides = []): Facility
    {
        return Facility::create(array_merge([
            'name' => 'Community Hall', 'slug' => 'community-hall', 'barangay' => 'Taft',
            'category' => 'Facility', 'description' => str_repeat('Full description. ', 20),
            'location' => 'Barangay Taft', 'capacity' => 15, 'status' => 'Available',
            'hourly_rate' => '1234.50', 'reservation_access' => 'all_registered_users',
        ], $overrides));
    }

    private function dialog(string $html, string $slug): string
    {
        $this->assertSame(1, preg_match('/<dialog\b[^>]*id="view-facility-'.preg_quote($slug, '/').'"[^>]*>(.*?)<\/dialog>/s', $html, $matches));

        return $matches[1];
    }

    public function test_admin_view_uses_existing_barangay_scope_even_with_another_barangay_filter(): void
    {
        $local = $this->facility();
        $other = $this->facility(['name' => 'Washington Equipment', 'slug' => 'washington-equipment', 'barangay' => 'Washington']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $response = $this->actingAs($admin)->get(route('facilities', ['barangay' => 'Washington']))->assertOk();
        $response->assertSee('id="view-facility-'.$local->slug.'"', false)
            ->assertDontSee('id="view-facility-'.$other->slug.'"', false)->assertDontSee($other->name);
        $this->get(route('facilities.show', $other->slug))->assertNotFound();
    }

    public function test_both_management_roles_share_read_only_details_and_existing_gallery(): void
    {
        Storage::fake('public');
        $facility = $this->facility();
        foreach ([[800, 400], [300, 600]] as $index => [$width, $height]) {
            $path = UploadedFile::fake()->image('photo-'.$index.'.png', $width, $height)->store('facilities', 'public');
            $facility->photos()->create(['path' => $path, 'sort_order' => $index]);
        }
        foreach (['admin', 'super_admin'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role, 'barangay' => 'Taft']))->get(route('facilities'))->assertOk();
            $response->assertSee('facility-view-modal', false)->assertSee('data-modal-size="large"', false);
            $dialog = $this->dialog($response->getContent(), $facility->slug);
            foreach ([$facility->name, trim($facility->description), 'Barangay / Location', 'Barangay Taft', 'All Registered Users', '₱1,234.50 / hour', 'Available', 'Facility', '15 persons'] as $value) {
                $this->assertStringContainsString($value, $dialog);
            }
            $this->assertSame(2, substr_count($dialog, 'data-carousel-slide'));
            $this->assertSame(1, substr_count($dialog, 'data-facility-carousel'));
            $this->assertSame(1, substr_count($dialog, 'Barangay Taft'));
            $this->assertStringNotContainsString('<dt>Location</dt>', $dialog);
            $this->assertSame(0, preg_match('/<(form|input|textarea|select|a)\b/', $dialog));
            $this->assertStringNotContainsString('View Calendar', $dialog);
            $this->assertStringContainsString('class="facility-modal-secondary" data-modal-close>Close', $dialog);
        }
        $this->assertDatabaseCount('facilities', 1);
        $this->assertDatabaseCount('facility_photos', 2);
    }

    public function test_super_admin_can_view_other_barangays_and_distinct_locations_are_retained(): void
    {
        $this->facility();
        $equipment = $this->facility(['name' => 'Washington Chairs', 'slug' => 'washington-chairs', 'barangay' => 'Washington', 'category' => 'Equipment', 'location' => 'Storage Room B', 'capacity' => 1, 'reservation_access' => 'residents_only', 'status' => 'Unavailable']);
        $response = $this->actingAs(User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']))->get(route('facilities'))->assertOk();
        $dialog = $this->dialog($response->getContent(), $equipment->slug);
        foreach (['<dt>Barangay</dt>', 'Washington', '<dt>Location</dt>', 'Storage Room B', 'Washington Residents Only', 'Equipment', '1 person', 'Unavailable'] as $value) {
            $this->assertStringContainsString($value, $dialog);
        }
        $this->assertStringNotContainsString('data-facility-carousel', $dialog);
        $this->get(route('facilities.show', $equipment->slug))->assertOk();
    }
}
