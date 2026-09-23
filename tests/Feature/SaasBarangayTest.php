<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\TestCase;

class SaasBarangayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        foreach ($this->testFacilities() as $facility) {
            Facility::create($facility);
        }
    }

    public function test_public_registration_creates_a_user_with_a_valid_barangay(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Ana User',
            'email' => 'ana@example.com',
            'barangay' => 'Washington',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'ana@example.com',
            'role' => 'user',
            'barangay' => 'Washington',
        ]);
    }

    public function test_public_registration_rejects_invalid_barangay(): void
    {
        $response = $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Ana User',
            'email' => 'invalid-barangay@example.com',
            'barangay' => 'Not A Barangay',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('barangay');
        $this->assertDatabaseMissing('users', ['email' => 'invalid-barangay@example.com']);
    }

    public function test_reservation_creation_stores_the_users_barangay(): void
    {
        $user = User::factory()->create(['barangay' => 'Washington']);

        $this->actingAs($user)->post(route('reservations.store', 'multi-purpose-court'), [
            'reservation_date' => now()->addDays(5)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Community practice',
            'attendees' => 15,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reservations', [
            'user_id' => $user->id,
            'barangay' => 'Washington',
            'status' => 'pending',
        ]);
    }

    public function test_admin_only_sees_reservations_from_their_barangay(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $washingtonUser = User::factory()->create(['name' => 'Washington Resident', 'barangay' => 'Washington']);
        $alegriaUser = User::factory()->create(['name' => 'Alegria Resident', 'barangay' => 'Alegria']);

        $this->reservation(['user_id' => $washingtonUser->id, 'barangay' => 'Washington']);
        $this->reservation(['user_id' => $alegriaUser->id, 'barangay' => 'Alegria']);

        $response = $this->actingAs($admin)->get(route('reservations.index'));

        $response->assertOk();
        $response->assertSee('Washington Resident');
        $response->assertDontSee('Alegria Resident');
    }

    public function test_admin_cannot_approve_another_barangay_reservation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $reservation = $this->reservation(['barangay' => 'Alegria']);

        $this->actingAs($admin)
            ->post(route('reservations.accept', $reservation))
            ->assertForbidden();

        $this->assertSame('pending', $reservation->fresh()->status);
    }

    public function test_super_admin_can_create_admin_accounts(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Washington']);

        $this->actingAs($superAdmin)->post(route('admins.store'), [
            'name' => 'Washington Admin',
            'email' => 'admin@example.com',
            'barangay' => 'Washington',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => 'admin',
            'barangay' => 'Washington',
        ]);
    }

    public function test_non_super_admin_cannot_create_admin_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);

        $this->actingAs($admin)->post(route('admins.store'), [
            'name' => 'Other Admin',
            'email' => 'other-admin@example.com',
            'barangay' => 'Washington',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'other-admin@example.com']);
    }

    private function reservation(array $overrides = []): Reservation
    {
        $facilitySlug = $overrides['facility_slug'] ?? 'multi-purpose-court';
        $barangay = $overrides['barangay'] ?? 'Washington';
        $facility = Facility::query()
            ->where('barangay', $barangay)
            ->where('slug', $facilitySlug)
            ->first();

        return Reservation::create(array_merge([
            'user_id' => User::factory()->create(['barangay' => $barangay])->id,
            'barangay' => $barangay,
            'facility_id' => $facility?->id,
            'facility_slug' => $facilitySlug,
            'facility_name' => $facility?->name ?? 'Multi-Purpose Court',
            'category' => $facility?->category ?? 'Facility',
            'location' => $facility?->location ?? 'Barangay Washington',
            'reservation_date' => now()->addDays(3)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'purpose' => 'Community activity',
            'attendees' => 25,
            'status' => 'pending',
        ], $overrides));
    }

    private function testFacilities(): array
    {
        return [
            [
                'barangay' => 'Washington',
                'slug' => 'multi-purpose-court',
                'name' => 'Multi-Purpose Court',
                'description' => 'Outdoor covered court for sports and recreational activities.',
                'list_description' => 'Outdoor covered court for sports and recreational activities.',
                'category' => 'Facility',
                'capacity' => 200,
                'location' => 'Barangay Washington',
                'status' => 'Available',
            ],
        ];
    }
}
