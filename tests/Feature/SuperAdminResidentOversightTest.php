<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AdminPermission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SuperAdminResidentOversightTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $local;

    private User $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        Mail::fake();
        Queue::fake();
        $this->super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->local = User::factory()->create(['role' => 'user', 'barangay' => 'Taft', 'name' => 'Local Resident', 'email' => 'local@example.test']);
        $this->foreign = User::factory()->create(['role' => 'user', 'barangay' => 'Washington', 'name' => 'Foreign Resident', 'email' => 'foreign@example.test']);
        $this->actingAs($this->super);
    }

    public function test_super_admin_lists_only_residents_across_barangays_and_keeps_separate_navigation(): void
    {
        $response = $this->get(route('residents.index'))->assertOk()
            ->assertSee('Resident Management')->assertSee('Admin Management')->assertSee('across all barangays')
            ->assertSee($this->local->email)->assertSee($this->foreign->email)
            ->assertDontSee($this->admin->email)
            ->assertSee('management-table-scroll', false)->assertSee('management-table reservation-datatable', false)
            ->assertSee('reservation-table-pagination', false)->assertSee('reservation-status-accepted', false)
            ->assertSee('data-account-status', false)->assertSee('id="account-confirmation"', false)
            ->assertDontSee('id="create-admin"', false)->assertDontSee('Add Resident')
            ->assertSee(route('residents.index'))->assertSee(route('admins.index'));
        // The authenticated Super Admin email now appears in the mobile profile header.
        // Neither administrator belongs in the resident listing itself.
        preg_match('/<main\b[^>]*>(.*?)<\/main>/s', $response->getContent(), $main);
        $this->assertStringNotContainsString($this->admin->email, $main[1]);
        $this->assertStringNotContainsString($this->super->email, $main[1]);
        $this->assertEqualsCanonicalizing([$this->local->id, $this->foreign->id], $response->viewData('accounts')->modelKeys());
        $this->assertTrue($response->viewData('canManageAccounts'));
    }

    public function test_super_admin_searches_name_and_email_without_expanding_resident_role_scope(): void
    {
        foreach (['Foreign', 'foreign@example.test'] as $search) {
            $this->get(route('residents.index', ['search' => $search]))
                ->assertOk()->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$this->foreign->id]);
        }
        $this->get(route('residents.index', ['search' => $this->admin->email]))->assertViewHas('accounts', fn ($rows) => $rows->isEmpty());
        $this->get(route('residents.index', ['search' => "' OR 1=1 --"]))->assertViewHas('accounts', fn ($rows) => $rows->isEmpty());
    }

    public function test_super_admin_barangay_and_active_inactive_filters_combine(): void
    {
        $this->foreign->forceFill(['is_active' => false])->save();
        foreach (['Taft' => $this->local, 'Washington' => $this->foreign] as $barangay => $account) {
            $this->get(route('residents.index', ['barangay' => $barangay]))
                ->assertOk()->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$account->id]);
        }
        foreach (['active' => $this->local, 'inactive' => $this->foreign] as $status => $account) {
            $this->get(route('residents.index', ['status' => $status]))
                ->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$account->id]);
        }
        $this->get(route('residents.index', ['search' => 'Resident', 'barangay' => 'Washington', 'status' => 'inactive']))
            ->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$this->foreign->id]);
        $this->get(route('residents.index', ['barangay' => 'Taft', 'status' => 'inactive']))->assertSee('No accounts match your filters.');
    }

    public function test_resident_sorting_and_pagination_remain_server_side_and_keep_filters(): void
    {
        $this->get(route('residents.index', ['sort' => 'name', 'direction' => 'desc']))
            ->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$this->local->id, $this->foreign->id]);
        User::factory()->count(16)->create(['role' => 'user', 'barangay' => 'Washington']);
        $response = $this->get(route('residents.index', ['barangay' => 'Washington', 'sort' => 'email', 'direction' => 'desc']))->assertOk();
        $rows = $response->viewData('accounts');
        $this->assertSame(17, $rows->total());
        $this->assertSame(15, $rows->count());
        $this->assertStringContainsString('barangay=Washington', $rows->nextPageUrl());
        $this->assertStringContainsString('sort=email', $rows->nextPageUrl());
        $this->get($rows->nextPageUrl())->assertViewHas('accounts', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 2);
        $this->get(route('residents.index', ['sort' => 'password']))->assertSessionHasErrors('sort');
        $this->get(route('residents.index', ['direction' => 'desc; DROP TABLE users']))->assertSessionHasErrors('direction');
    }

    public function test_super_admin_views_safe_resident_details_and_cross_barangay_history_without_mutation_forms(): void
    {
        $this->foreign->forceFill(['remember_token' => 'private-remember-token', 'two_factor_method' => 'email'])->save();
        $facility = Facility::create(['barangay' => 'Taft', 'slug' => 'oversight-court', 'name' => 'Oversight Court', 'category' => 'Facility', 'description' => 'Court', 'capacity' => 20, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => 100]);
        Reservation::create(['user_id' => $this->foreign->id, 'facility_id' => $facility->id, 'barangay' => 'Taft', 'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => 'Facility', 'location' => 'Taft', 'reservation_date' => today()->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'purpose' => 'Activity', 'attendees' => 5, 'status' => 'accepted', 'total_payment' => 100, 'hourly_rate_snapshot' => 100]);
        $response = $this->get(route('residents.show', $this->foreign))->assertOk()
            ->assertSee($this->foreign->email)->assertSee('Washington')->assertSee('Oversight Court')->assertSee('Managing Barangay')
            ->assertDontSee('private-remember-token')->assertDontSee($this->foreign->password)
            ->assertDontSee('remember_token')->assertDontSee('two_factor_method')
            ->assertSee('data-account-status', false)->assertSee('id="account-confirmation"', false);
        $this->assertTrue($response->viewData('canManageAccounts'));
        foreach ([$this->admin, $this->super] as $account) {
            $this->get(route('residents.show', $account))->assertNotFound();
        }
    }

    public function test_super_admin_can_activate_and_deactivate_residents_across_barangays_and_ignores_forged_identity_fields(): void
    {
        $this->foreign->forceFill(['is_active' => false])->save();
        $this->patchJson(route('residents.status', $this->foreign), [
            'is_active' => true, 'role' => 'super_admin', 'barangay' => 'Taft', 'name' => 'Forged',
            'email' => 'forged@example.test', 'password' => 'must-not-be-used',
        ])->assertOk();
        $this->assertTrue($this->foreign->fresh()->is_active);
        $this->assertSame('user', $this->foreign->fresh()->role);
        $this->assertSame('Washington', $this->foreign->fresh()->barangay);
        $this->assertSame('Foreign Resident', $this->foreign->fresh()->name);
        $this->assertSame('foreign@example.test', $this->foreign->fresh()->email);
        $this->patch(route('residents.status', $this->local), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->local->fresh()->is_active);
        $this->patchJson(route('residents.status', $this->foreign), ['is_active' => false])->assertOk();
        $this->assertFalse($this->foreign->fresh()->is_active);
    }

    public function test_super_admin_cannot_edit_delete_or_change_resident_identity_through_account_urls(): void
    {
        $before = $this->local->fresh()->getAttributes();
        $this->patchJson(route('residents.show', $this->local), ['name' => 'Changed', 'role' => 'admin', 'barangay' => 'Washington', 'password' => 'changed-password'])->assertStatus(405);
        $this->deleteJson(route('residents.show', $this->local))->assertStatus(405);
        $this->patchJson(route('admins.status', $this->local), ['is_active' => false])->assertNotFound();
        $this->assertSame($before, $this->local->fresh()->getAttributes());
    }

    public function test_barangay_admin_retains_own_resident_actions_and_cannot_escape_scope_with_filters_or_ids(): void
    {
        $this->actingAs($this->admin)->get(route('residents.index', ['barangay' => 'Washington', 'barangay_id' => 999]))
            ->assertOk()->assertSee('data-account-status', false)->assertSee($this->local->email)->assertDontSee($this->foreign->email)
            ->assertViewHas('canManageAccounts', true);
        $this->get(route('residents.show', $this->foreign))->assertNotFound();
        $this->patchJson(route('residents.status', $this->foreign), ['is_active' => false])->assertNotFound();
        $this->patchJson(route('residents.status', $this->local), ['is_active' => false])->assertOk();
        $this->assertFalse($this->local->fresh()->is_active);
        $this->patchJson(route('residents.status', $this->local), ['is_active' => true])->assertOk();
        $this->assertTrue($this->local->fresh()->is_active);
        $this->assertTrue($this->foreign->fresh()->is_active);
    }

    public function test_individual_resident_view_and_manage_restrictions_apply_to_routes_and_super_admin_remains_unrestricted(): void
    {
        $this->admin->restrictedPermissions()->create(['permission' => AdminPermission::RESIDENTS_VIEW->value]);
        $this->actingAs($this->admin)->get(route('residents.index'))->assertForbidden();
        $this->get(route('residents.show', $this->local))->assertForbidden();
        $this->admin->restrictedPermissions()->delete();

        $this->admin->restrictedPermissions()->create(['permission' => AdminPermission::RESIDENTS_MANAGE->value]);
        $response = $this->actingAs($this->admin)->get(route('residents.index'))->assertOk()
            ->assertDontSee('data-account-status', false)->assertViewHas('canManageAccounts', false);
        $this->assertSame([$this->local->id], $response->viewData('accounts')->modelKeys());
        $this->patchJson(route('residents.status', $this->local), ['is_active' => false])->assertForbidden();
        $this->assertTrue($this->local->fresh()->is_active);

        // The normalized restriction model rejects restrictions on Super Admins.
        try {
            $this->super->restrictedPermissions()->create(['permission' => AdminPermission::RESIDENTS_VIEW->value]);
            $this->fail('Super Admin permission restrictions must be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame(0, $this->super->restrictedPermissions()->count());
        }
        $this->actingAs($this->super)->get(route('residents.index'))->assertOk()->assertSee($this->foreign->email);
        $this->patchJson(route('residents.status', $this->foreign), ['is_active' => false])->assertOk();
        $this->assertFalse($this->foreign->fresh()->is_active);
    }

    public function test_admin_management_remains_separate_and_mutable_for_super_admin(): void
    {
        $this->get(route('admins.index'))->assertOk()->assertSee('Add Admin')->assertSee($this->admin->email)
            ->assertDontSee($this->local->email)->assertDontSee($this->foreign->email)->assertViewHas('canManageAccounts', true);
        $this->patchJson(route('admins.status', $this->admin), ['is_active' => false])->assertOk();
        $this->assertFalse($this->admin->fresh()->is_active);
        $this->patchJson(route('admins.status', $this->admin), ['is_active' => true])->assertOk();
        $this->get(route('admins.create'))->assertOk();
    }

    public function test_resident_and_guest_cannot_open_resident_oversight(): void
    {
        $this->actingAs($this->local)->get(route('residents.index'))->assertForbidden();
        $this->get(route('residents.show', $this->foreign))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get(route('residents.index'))->assertRedirect(route('login'));
    }

    public function test_reading_searching_filtering_and_paging_residents_emit_no_notifications_mail_or_jobs(): void
    {
        $before = User::orderBy('id')->get()->map->getAttributes()->all();
        $this->get(route('residents.index'))->assertOk();
        $this->get(route('residents.index', ['search' => 'Resident', 'barangay' => 'Washington', 'status' => 'active', 'page' => 1]))->assertOk();
        $this->get(route('residents.show', $this->foreign))->assertOk();
        $this->assertSame($before, User::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(0, DB::table('notifications')->count());
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }
}
