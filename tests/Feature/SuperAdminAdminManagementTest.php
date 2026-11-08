<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AdminPermission;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SuperAdminAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        $this->super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->resident = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
        $this->actingAs($this->super);
    }

    public function test_super_admin_can_view_search_filter_and_edit_admins_across_barangays(): void
    {
        $other = User::factory()->create(['role' => 'admin', 'barangay' => 'Mabua', 'name' => 'Needle Admin']);
        $this->get(route('admins.index'))->assertOk()->assertSee($this->admin->email)->assertSee($other->email)
            ->assertSee('management-table-scroll', false)->assertSee('reservation-table-pagination', false)
            ->assertSee('Assigned Barangay')->assertSee('Permissions');
        $this->get(route('admins.index', ['search' => 'Needle', 'barangay' => 'Mabua']))
            ->assertOk()->assertSee($other->email)->assertDontSee($this->admin->email);
        $this->get(route('admins.show', $this->admin))->assertOk()->assertSee('Washington');
        $edit = $this->get(route('admins.edit', $this->admin))->assertOk()
            ->assertSee('Assigned Barangay')->assertSee('Save Changes')
            ->assertSee('admin-edit-confirmation')->assertSee('Confirm Changes')
            ->assertDontSee('type="password"', false);
        $this->assertSame($this->admin->id, $edit->viewData('admin')->id);
    }

    public function test_super_admin_can_edit_admin_details_and_reassign_without_touching_history_or_restrictions(): void
    {
        $this->admin->restrictedPermissions()->create(['permission' => AdminPermission::RESERVATIONS_VIEW->value]);
        $originalFacility = Facility::create([
            'barangay' => 'Washington', 'slug' => 'old-town-hall', 'name' => 'Old Town Hall', 'category' => 'Facility',
            'description' => 'Hall', 'capacity' => 80, 'location' => 'Washington', 'status' => 'Available', 'hourly_rate' => 50,
        ]);
        $reservation = Reservation::create([
            'user_id' => $this->resident->id, 'facility_id' => $originalFacility->id, 'barangay' => 'Washington',
            'facility_slug' => $originalFacility->slug, 'facility_name' => $originalFacility->name, 'category' => 'Facility',
            'location' => 'Washington', 'reservation_date' => '2026-12-20', 'start_time' => '09:00', 'end_time' => '10:00',
            'purpose' => 'Community event', 'attendees' => 20, 'status' => 'accepted', 'total_payment' => '50.00',
            'hourly_rate_snapshot' => '50.00',
        ]);
        $payment = Payment::create(['reservation_id' => $reservation->id, 'amount' => '50.00', 'payment_status' => 'paid']);
        $reservationBefore = $reservation->fresh()->getAttributes();
        $facilityBefore = $originalFacility->fresh()->getAttributes();
        $paymentBefore = $payment->fresh()->getAttributes();

        $this->patch(route('admins.update', $this->admin), [
            'name' => 'Updated Administrator', 'email' => 'updated-admin@example.test', 'phone_number' => '09171234567',
            'barangay' => 'Mabua', 'role' => 'super_admin', 'id' => $this->super->id, 'is_active' => false,
        ])->assertRedirect(route('verification.notice'));

        $updated = $this->admin->fresh();
        $this->assertSame('Updated Administrator', $updated->name);
        $this->assertSame('updated-admin@example.test', $updated->email);
        $this->assertNull($updated->email_verified_at);
        $this->assertSame('Mabua', $updated->barangay);
        $this->assertSame('admin', $updated->role);
        $this->assertTrue($updated->is_active);
        $this->assertTrue($updated->restrictedPermissions()->where('permission', AdminPermission::RESERVATIONS_VIEW->value)->exists());
        $this->assertSame($reservationBefore, $reservation->fresh()->getAttributes());
        $this->assertSame($facilityBefore, $originalFacility->fresh()->getAttributes());
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
    }

    public function test_reassigned_admin_uses_new_barangay_for_future_authorization_and_loses_old_barangay_scope(): void
    {
        Facility::create([
            'barangay' => 'Washington', 'slug' => 'previous-barangay-resource', 'name' => 'Previous Barangay Resource',
            'category' => 'Facility', 'description' => 'Old resource', 'capacity' => 10, 'location' => 'Washington',
            'status' => 'Available', 'hourly_rate' => 10,
        ]);
        Facility::create([
            'barangay' => 'Mabua', 'slug' => 'new-barangay-resource', 'name' => 'New Barangay Resource',
            'category' => 'Facility', 'description' => 'New resource', 'capacity' => 10, 'location' => 'Mabua',
            'status' => 'Available', 'hourly_rate' => 10,
        ]);
        $this->patch(route('admins.update', $this->admin), [
            'name' => $this->admin->name, 'email' => $this->admin->email, 'phone_number' => $this->admin->phone_number,
            'barangay' => 'Mabua',
        ])->assertRedirect(route('admins.show', $this->admin));

        $freshAdmin = $this->admin->fresh();
        $authorization = app(\App\Services\Authorization::class);
        $this->assertTrue($authorization->managesBarangay($freshAdmin, 'Mabua'));
        $this->assertFalse($authorization->managesBarangay($freshAdmin, 'Washington'));

        // A new request reloads the account and the reservation scope follows its stored barangay.
        Auth::logout();
        $this->actingAs($freshAdmin)->get(route('reservations.index'))->assertOk()
            ->assertDontSee($this->resident->name);
        $this->get(route('residents.index'))->assertOk()->assertDontSee($this->resident->email);
        $this->get(route('facilities.show', 'previous-barangay-resource'))->assertNotFound();
        $this->get(route('facilities.show', 'new-barangay-resource'))->assertOk()->assertSee('New Barangay Resource');
    }

    public function test_admins_residents_inactive_super_admin_and_self_targets_cannot_manage_admin_accounts(): void
    {
        foreach ([$this->admin, $this->resident] as $actor) {
            $this->actingAs($actor);
            $this->get(route('admins.index'))->assertForbidden();
            $this->get(route('admins.edit', $this->admin))->assertForbidden();
            $this->patch(route('admins.update', $this->admin), [])->assertForbidden();
            $this->patch(route('admins.status', $this->admin), ['is_active' => false])->assertForbidden();
            $this->post(route('admins.password-reset', $this->admin))->assertForbidden();
        }

        $inactiveSuper = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
        $this->actingAs($inactiveSuper)->get(route('admins.index'))->assertRedirect(route('login'));
        $this->actingAs($inactiveSuper)->patch(route('admins.update', $this->admin), [
            'name' => 'Inactive Change', 'email' => $this->admin->email, 'phone_number' => '', 'barangay' => 'Mabua',
        ])->assertRedirect(route('login'));
        $this->actingAs($inactiveSuper)->patch(route('admins.status', $this->admin), ['is_active' => false])->assertRedirect(route('login'));
        $this->actingAs($inactiveSuper)->post(route('admins.password-reset', $this->admin))->assertRedirect(route('login'));
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'barangay' => 'Washington']);

        $this->actingAs($this->super);
        $this->get(route('admins.edit', $this->super))->assertNotFound();
        $this->patch(route('admins.update', $this->super), ['name' => 'Escalated'])->assertNotFound();
        $this->assertSame('super_admin', $this->super->fresh()->role);
    }

    public function test_invalid_barangay_duplicate_email_and_forged_role_are_rejected_or_ignored(): void
    {
        $this->from(route('admins.edit', $this->admin))->patch(route('admins.update', $this->admin), [
            'name' => 'Changed', 'email' => $this->admin->email, 'phone_number' => '', 'barangay' => 'Not a Barangay',
        ])->assertSessionHasErrors('barangay');

        $duplicate = User::factory()->create(['email' => 'duplicate@example.test']);
        $this->patch(route('admins.update', $this->admin), [
            'name' => 'Changed', 'email' => $duplicate->email, 'phone_number' => '', 'barangay' => 'Mabua',
        ])->assertSessionHasErrors('email');
        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame('Washington', $this->admin->fresh()->barangay);

        $this->get(route('admins.create'))->assertOk()->assertSee('admin-barangay')->assertSee('Select barangay');
        $this->post(route('admins.store'), [
            'name' => 'New Admin', 'email' => 'new-admin@example.test', 'phone_number' => '09171234567',
            'barangay' => 'Mabua', 'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'super_admin', 'id' => $this->super->id, 'is_active' => false,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new-admin@example.test', 'role' => 'admin', 'barangay' => 'Mabua', 'is_active' => true]);
    }

    public function test_email_two_factor_prevents_admin_email_change(): void
    {
        $originalEmail = $this->admin->email;
        $this->admin->forceFill(['two_factor_method' => 'email', 'email_verified_at' => now()])->save();
        $this->patch(route('admins.update', $this->admin), [
            'name' => $this->admin->name, 'email' => 'new-address@example.test', 'phone_number' => $this->admin->phone_number,
            'barangay' => $this->admin->barangay,
        ])->assertSessionHasErrors('email');
        $this->assertSame($originalEmail, $this->admin->fresh()->email);
        $this->assertNotNull($this->admin->fresh()->email_verified_at);
    }

    public function test_super_admin_can_trigger_secure_password_recovery_without_receiving_password_or_token(): void
    {
        $passwordBefore = $this->admin->password;
        $this->get(route('admins.show', $this->admin))->assertOk()
            ->assertSee('Send Password Reset Link')->assertDontSee('reset_token')->assertDontSee($this->admin->password);
        $this->post(route('admins.password-reset', $this->admin))->assertSessionHasNoErrors();
        Notification::assertSentTo($this->admin, ResetPassword::class);
        $this->assertSame($passwordBefore, $this->admin->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $this->admin->email]);
        $this->assertStringNotContainsString('token', session('account_status', ''));
    }
}
