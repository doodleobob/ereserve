<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_merge(['name' => 'Contact Resident', 'email' => 'contact@example.com',
            'phone_number' => '09171234567', 'barangay' => 'Washington',
            'password' => 'password123', 'password_confirmation' => 'password123'], $overrides);
    }

    public function test_registration_normalizes_phone_and_keeps_it_out_of_serialized_users(): void
    {
        Notification::fake();
        $this->post(route('register.store'), $this->registration())->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'contact@example.com')->firstOrFail();
        $this->assertSame('+639171234567', $user->phone_number);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('user', $user->role);
        $this->assertArrayNotHasKey('phone_number', $user->toArray());
    }

    public static function invalidNumbers(): array
    {
        return [[null], [''], ['12345'], ['091712345678'], ['+63917123456'],
            ['+12025550123'], ['09abcdefghj'], [123456789], [['09171234567']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNumbers')]
    public function test_registration_rejects_invalid_or_missing_phone(mixed $number): void
    {
        $this->from(route('register'))->post(route('register.store'), $this->registration(['phone_number' => $number]))
            ->assertRedirect(route('register'))->assertSessionHasErrors('phone_number');
        $this->assertDatabaseMissing('users', ['email' => 'contact@example.com']);
    }

    public function test_admin_creation_uses_the_same_required_phone_rules(): void
    {
        Notification::fake();
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($super)->post(route('admins.store'), $this->registration(['phone_number' => null]))
            ->assertSessionHasErrors('phone_number');
        $this->post(route('admins.store'), $this->registration(['phone_number' => '+639171234567']))->assertSessionHasNoErrors();
        $admin = User::where('email', 'contact@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('+639171234567', $admin->phone_number);
        $this->get(route('admins.index'))->assertOk()->assertSee('+639171234567');
        $this->get(route('admins.show', $admin))->assertOk()->assertSee('+639171234567');
    }

    public function test_existing_accounts_can_login_and_update_only_their_own_contact(): void
    {
        $other = User::factory()->create(['phone_number' => '09181112222']);
        foreach (['user', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'password' => 'password123']);
            $this->assertNull($user->phone_number);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])->assertRedirect();
            $this->assertAuthenticatedAs($user);
            $this->get(route('profile.edit'))->assertOk()->assertSee('Add a phone number');
            $this->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email])->assertSessionHasNoErrors();
            $this->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email,
                'phone_number' => '09171234567', 'id' => $other->id, 'role' => 'super_admin'])
                ->assertSessionHasNoErrors();
            $this->assertSame('+639171234567', $user->fresh()->phone_number);
            $this->assertSame($role, $user->fresh()->role);
            $this->assertSame('+639181112222', $other->fresh()->phone_number);
            $this->get(route('profile.edit'))->assertSee('+639171234567');
            $this->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'phone_number' => 'invalid'])
                ->assertSessionHasErrors('phone_number');
            $this->assertSame('+639171234567', $user->fresh()->phone_number);
            $this->post(route('logout'));
        }
    }

    public function test_contact_visibility_respects_roles_and_barangays(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $resident = User::factory()->create(['barangay' => 'Washington', 'phone_number' => '09171234567']);
        $outside = User::factory()->create(['barangay' => 'Mabua', 'phone_number' => '09181112222']);
        $facility = Facility::create(['barangay' => 'Washington', 'slug' => 'court', 'name' => 'Court',
            'category' => 'Facility', 'description' => 'Court', 'capacity' => 20, 'location' => 'Washington', 'status' => 'Available']);
        foreach ([$resident, $outside] as $owner) {
            Reservation::create(['user_id' => $owner->id, 'barangay' => $owner->barangay,
                'facility_id' => $facility->id, 'facility_slug' => 'court', 'facility_name' => 'Court',
                'category' => 'Facility', 'location' => $owner->barangay, 'reservation_date' => today()->toDateString(),
                'start_time' => '08:00', 'end_time' => '09:00', 'purpose' => 'Contact test', 'attendees' => 5, 'status' => 'accepted']);
        }
        $this->actingAs($admin)->get(route('residents.index'))->assertOk()->assertSee($resident->phone_number)->assertDontSee($outside->phone_number);
        $this->get(route('residents.show', $resident))->assertOk()->assertSee($resident->phone_number);
        $this->get(route('residents.show', $outside))->assertNotFound();
        $this->get(route('reservations.index'))->assertOk()->assertSee($resident->email)->assertSee($resident->phone_number)->assertDontSee($outside->phone_number);
        $this->actingAs($resident)->get(route('residents.show', $outside))->assertForbidden();
        $this->get(route('admins.index'))->assertForbidden();
        foreach (['reservations.index', 'facilities', 'dashboard'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee($resident->phone_number)->assertDontSee($outside->phone_number);
        }
        $this->get(route('facilities.show', 'court'))->assertOk()->assertDontSee($resident->phone_number)->assertDontSee($outside->phone_number);
        $this->post(route('logout'));
        $this->get(route('residents.show', $resident))->assertRedirect(route('login'));
    }
}
