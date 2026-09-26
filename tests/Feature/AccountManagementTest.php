<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use App\Notifications\SecurityCode;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
    }

    private function account(string $role = 'user', string $barangay = 'Washington', array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'barangay' => $barangay], $attributes));
    }

    public function test_resident_listing_is_scoped_even_when_search_matches_an_outsider(): void
    {
        $admin = $this->account('admin');
        $own = $this->account(attributes: ['name' => 'Matching Local']);
        $other = $this->account(barangay: 'Mabua', attributes: ['name' => 'Matching Outsider']);
        $otherAdmin = $this->account('admin', attributes: ['name' => 'Matching Admin']);
        $super = $this->account('super_admin', attributes: ['name' => 'Matching Super']);
        $this->actingAs($admin)->get(route('residents.index', ['search' => 'Matching']))
            ->assertOk()->assertSee($own->email)->assertDontSee($other->email)
            ->assertDontSee($otherAdmin->email)->assertDontSee($super->email);
        $this->get(route('residents.show', $own))->assertOk()->assertSee($own->email)->assertSee('Reservation Activity');
    }

    public function test_roles_cannot_access_other_management_areas_or_actions(): void
    {
        $target = $this->account();
        foreach (['user', 'super_admin'] as $role) {
            $this->actingAs($this->account($role));
            $this->get(route('residents.index'))->assertForbidden();
            $this->get(route('residents.show', $target))->assertForbidden();
            $this->patch(route('residents.status', $target), ['is_active' => false])->assertForbidden();
        }
        foreach (['user', 'admin'] as $role) {
            $this->actingAs($this->account($role));
            $this->get(route('admins.index'))->assertForbidden();
            $this->get(route('admins.create'))->assertForbidden();
            $this->get(route('admins.show', $target))->assertForbidden();
            $this->post(route('admins.store'), [])->assertForbidden();
            $this->patch(route('admins.status', $target), ['is_active' => false])->assertForbidden();
        }
    }

    public function test_resident_ids_cannot_escape_barangay_or_role_scope(): void
    {
        $this->actingAs($this->account('admin'));
        foreach ([$this->account('user', 'Mabua'), $this->account('admin'), $this->account('super_admin')] as $target) {
            $this->get(route('residents.show', $target))->assertNotFound();
            foreach ([false, true] as $status) {
                $this->patch(route('residents.status', $target), ['is_active' => $status])->assertNotFound();
            }
            $this->assertTrue($target->fresh()->is_active);
        }
    }

    public function test_super_admin_only_lists_and_manages_admins(): void
    {
        $super = $this->account('super_admin');
        $admin = $this->account('admin');
        $resident = $this->account();
        $this->actingAs($super)->get(route('admins.index'))->assertOk()->assertSee($admin->email)->assertDontSee($resident->email);
        $this->get(route('admins.show', $admin))->assertOk()->assertSee($admin->email);
        foreach ([$super, $this->account('super_admin'), $resident] as $protected) {
            $this->get(route('admins.show', $protected))->assertNotFound();
            foreach ([false, true] as $status) {
                $this->patch(route('admins.status', $protected), ['is_active' => $status])->assertNotFound();
            }
            $this->assertTrue($protected->fresh()->is_active);
        }
        $this->get(route('admins.create'))->assertOk();
        $this->post(route('admins.store'), ['name' => 'New Admin', 'email' => 'new-admin@example.com',
            'phone_number' => '09171234567',
            'barangay' => 'Mabua', 'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'super_admin', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new-admin@example.com', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_deactivation_and_reactivation_control_login_for_both_account_types(): void
    {
        foreach (['residents' => ['admin', 'user'], 'admins' => ['super_admin', 'admin']] as $routes => [$managerRole, $targetRole]) {
            $manager = $this->account($managerRole);
            $target = $this->account($targetRole);
            $this->actingAs($manager)->patch(route($routes.'.status', $target), ['is_active' => false])->assertSessionHasNoErrors();
            $this->assertFalse($target->fresh()->is_active);
            Auth::logout();
            $this->post(route('login.store'), ['email' => $target->email, 'password' => 'password'])
                ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);
            $this->assertGuest();
            $this->actingAs($manager)->patch(route($routes.'.status', $target), ['is_active' => true])->assertSessionHasNoErrors();
            Auth::logout();
            $this->post(route('login.store'), ['email' => $target->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($target);
            Auth::logout();
        }
    }

    public function test_deactivation_preserves_reservations_payments_and_notifications(): void
    {
        $admin = $this->account('admin');
        $resident = $this->account();
        $reservation = Reservation::create(['user_id' => $resident->id, 'barangay' => 'Washington',
            'facility_slug' => 'court', 'facility_name' => 'Community Court', 'category' => 'facility', 'location' => 'Washington',
            'reservation_date' => '2026-10-01', 'start_time' => '08:00', 'end_time' => '10:00',
            'purpose' => 'Practice', 'attendees' => 5, 'status' => 'booked', 'hourly_rate_snapshot' => '100.00', 'total_payment' => '200.00']);
        $resident->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'reservation', 'data' => ['message' => 'Booked']]);
        $before = $reservation->fresh()->getAttributes();
        $this->actingAs($admin)->patch(route('residents.status', $resident), ['is_active' => false]);
        $this->get(route('residents.show', $resident))->assertOk()->assertSee('Community Court')->assertSee('200.00');
        $this->actingAs($this->account('super_admin'))->patch(route('admins.status', $admin), ['is_active' => false]);
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertSame(1, $resident->notifications()->count());
        $this->assertDatabaseHas('users', ['id' => $resident->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_search_status_barangay_filters_and_pagination(): void
    {
        $super = $this->account('super_admin');
        $match = $this->account('admin', 'Mabua', ['name' => 'Needle', 'is_active' => false]);
        $excluded = $this->account('admin', 'Washington', ['name' => 'Needle Outside', 'is_active' => false]);
        $this->actingAs($super)->get(route('admins.index', ['search' => $match->email, 'barangay' => 'Mabua', 'status' => 'inactive']))
            ->assertOk()->assertSee($match->email)->assertDontSee($excluded->email);
        $admin = $this->account('admin');
        User::factory()->count(16)->create(['role' => 'user', 'barangay' => 'Washington', 'name' => 'Search Resident']);
        $inactive = $this->account(attributes: ['name' => 'Search Inactive', 'is_active' => false]);
        $this->actingAs($admin)->get(route('residents.index', ['search' => 'Search', 'status' => 'active']))
            ->assertOk()->assertDontSee($inactive->email)->assertSee('Next')
            ->assertViewHas('accounts', fn ($accounts) => $accounts->count() === 15 && $accounts->total() === 16 && str_contains($accounts->nextPageUrl(), 'status=active'));
        $this->get(route('residents.index', ['status' => 'inactive']))->assertSee($inactive->email);
    }

    public function test_active_sessions_and_pending_two_factor_logins_are_blocked(): void
    {
        $user = $this->account(attributes: ['is_active' => false]);
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $user->is_active = true;
        $user->two_factor_method = 'email';
        $user->save();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $user->is_active = false;
        $user->save();
        $code = Notification::sent($user, SecurityCode::class)->last()->toMail($user)->viewData['code'];
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);
        $this->assertGuest();
        $this->assertFalse(session()->has('two_factor.login'));
    }

    public function test_status_endpoint_validates_input_and_cannot_modify_identity(): void
    {
        $target = $this->account();
        $this->actingAs($this->account('admin'));
        $this->patch(route('residents.status', $target), ['is_active' => 'invalid'])->assertSessionHasErrors('is_active');
        $this->patch(route('residents.status', $target), ['is_active' => false, 'role' => 'super_admin', 'barangay' => 'Mabua']);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'user', 'barangay' => 'Washington', 'is_active' => false]);
    }
}
