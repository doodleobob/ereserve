<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Support\OfficialUseScheduling;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class ModalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
        Storage::fake('public');
    }

    private function fields(array $overrides = []): array
    {
        return array_merge(['name' => 'Preview Court', 'category' => 'Facility', 'description' => 'Community activities', 'location' => 'Taft', 'capacity' => 50, 'status' => 'Available', 'hourly_rate' => '100.00', 'reservation_access' => 'residents_only'], $overrides);
    }

    private function renderFixture(string $name, $response): void
    {
        $response->assertOk();
        if ($directory = getenv('MODAL_RENDER_DIR')) {
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
    }

    public function test_facility_modal_json_keeps_uploads_and_existing_photo_replacement_rules(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($admin)->postJson(route('facilities.store'), $this->fields(['photos' => [UploadedFile::fake()->image('first.png')]]))
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('message', 'Facility added successfully.');
        $facility = Facility::firstOrFail();
        $oldPhoto = $facility->photos()->firstOrFail();
        $this->assertSame('Taft', $facility->barangay);
        Storage::disk('public')->assertExists($oldPhoto->path);
        $this->patchJson(route('facilities.update', $facility->slug), $this->fields(['name' => 'Updated Court', 'remove_photo_ids' => [$oldPhoto->id], 'photos' => [UploadedFile::fake()->image('second.png')]]))
            ->assertOk()->assertJsonPath('success', true);
        Storage::disk('public')->assertMissing($oldPhoto->path);
        $facility->refresh();
        $this->assertSame('Updated Court', $facility->name);
        $this->assertCount(1, $facility->photos);
        Storage::disk('public')->assertExists($facility->photos->first()->path);
    }

    public function test_facility_modal_returns_field_errors_and_preserves_role_and_barangay_guards(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($admin)->postJson(route('facilities.store'), $this->fields(['capacity' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->assertDatabaseCount('facilities', 0);
        $this->postJson(route('facilities.store'), $this->fields())->assertCreated();
        $facility = Facility::firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']));
        $this->patchJson(route('facilities.update', $facility->slug), $this->fields())->assertNotFound();
        $this->deleteJson(route('facilities.destroy', $facility->slug))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'user', 'barangay' => 'Taft']));
        $this->postJson(route('facilities.store'), $this->fields())->assertForbidden();
        $this->patchJson(route('facilities.update', $facility->slug), $this->fields())->assertForbidden();
        $this->deleteJson(route('facilities.destroy', $facility->slug))->assertForbidden();
        $this->assertDatabaseCount('facilities', 1);
    }

    public function test_facility_ajax_refresh_renders_new_edit_and_view_dialogs_then_removes_deleted_record(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']));
        $this->renderFixture('facilities-empty', $this->get(route('facilities')));
        $this->postJson(route('facilities.store'), $this->fields())->assertCreated();
        $facility = Facility::firstOrFail();
        $response = $this->get(route('facilities'));
        $response->assertSee('id="edit-facility-'.$facility->slug.'"', false)->assertSee('id="view-facility-'.$facility->slug.'"', false)
            ->assertSee('data-modal-action', false)->assertDontSee('role="dialog"', false)->assertDontSee('confirm(', false);
        $this->renderFixture('facilities-created', $response);
        $this->patchJson(route('facilities.update', $facility->slug), $this->fields(['name' => 'Updated Court']))->assertOk();
        $this->renderFixture('facilities-updated', $this->get(route('facilities')));
        $this->deleteJson(route('facilities.destroy', $facility->slug))->assertOk()->assertJsonPath('success', true);
        $this->get(route('facilities'))->assertDontSee('Updated Court');
        $this->assertDatabaseCount('facilities', 0);
    }

    public function test_admin_creation_uses_existing_fields_and_returns_json_validation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->postJson(route('admins.store'), ['name' => 'New Admin'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone_number', 'barangay', 'password']);
        $this->postJson(route('admins.store'), ['name' => 'New Admin', 'email' => 'new-admin@example.test', 'phone_number' => '09171234567', 'barangay' => 'Taft', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'super_admin'])
            ->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', ['email' => 'new-admin@example.test', 'role' => 'admin', 'barangay' => 'Taft']);
        $this->get(route('admins.index'))->assertSee('id="create-admin"', false)->assertSee('new-admin@example.test');
        $this->get(route('admins.create'))->assertOk()->assertSee('name="phone_number"', false);
    }

    public function test_admin_email_failure_reports_created_account_without_inviting_a_duplicate_retry(): void
    {
        Event::listen(Registered::class, fn () => throw new TransportException('Email unavailable'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->postJson(route('admins.store'), ['name' => 'New Admin', 'email' => 'mail-failure@example.test', 'phone_number' => '09171234567', 'barangay' => 'Taft', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('message', 'Admin account created, but the verification email could not be sent. The admin can log in and resend it.');
        $this->assertDatabaseHas('users', ['email' => 'mail-failure@example.test', 'role' => 'admin']);
    }

    public function test_account_status_ajax_preserves_scope_and_detail_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $resident = User::factory()->create(['name' => 'Preview Resident', 'role' => 'user', 'barangay' => 'Taft']);
        $other = User::factory()->create(['role' => 'user', 'barangay' => 'Washington']);
        $facility = Facility::create($this->fields(['barangay' => 'Taft', 'slug' => 'preview-court']));
        for ($index = 0; $index < 16; $index++) {
            $this->booking($resident, $facility, ['reservation_date' => today()->addDays($index + 3)->toDateString()]);
        }
        $this->actingAs($admin);
        $this->renderFixture('residents-active', $this->get(route('residents.index')));
        $this->renderFixture('resident-details', $this->get(route('residents.show', $resident)));
        $this->renderFixture('resident-details-page2', $this->get(route('residents.show', [$resident, 'page' => 2])));
        $this->patchJson(route('residents.status', $resident), ['is_active' => false])->assertOk()->assertJsonPath('message', 'Account deactivated.');
        $this->assertFalse($resident->fresh()->is_active);
        $this->renderFixture('residents-inactive', $this->get(route('residents.index')));
        $this->patchJson(route('residents.status', $resident), ['is_active' => true])->assertOk()->assertJsonPath('message', 'Account activated.');
        $this->patchJson(route('residents.status', $resident), ['is_active' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->patchJson(route('residents.status', $other), ['is_active' => false])->assertNotFound();
        $this->get(route('residents.show', $other))->assertNotFound();
        $this->get(route('residents.show', $resident))->assertSee('data-account-details', false)->assertSee('Reservation Activity');
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->renderFixture('admins', $this->get(route('admins.index')));
        $this->patchJson(route('admins.status', $admin), ['is_active' => false])->assertOk()->assertJsonPath('success', true);
        $this->patchJson(route('admins.status', $resident), ['is_active' => false])->assertNotFound();
        $this->assertDatabaseCount('reservations', 16);
        $this->assertSame('100.00', Reservation::firstOrFail()->total_payment);
    }

    private function booking(User $resident, Facility $facility, array $overrides = []): Reservation
    {
        return Reservation::create(array_merge(['user_id' => $resident->id, 'barangay' => 'Taft', 'facility_id' => $facility->id, 'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => $facility->category, 'location' => $facility->location, 'reservation_date' => today()->addDays(3)->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00', 'purpose' => 'Resident workshop', 'attendees' => 5, 'status' => 'accepted', 'total_payment' => 100, 'hourly_rate_snapshot' => 100], $overrides));
    }

    public function test_resident_conflict_confirmation_reuses_decision_rules_and_keeps_booking_and_payment(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $resident = User::factory()->create(['role' => 'user', 'barangay' => 'Taft']);
        $facility = Facility::create($this->fields(['barangay' => 'Taft', 'slug' => 'preview-court']));
        $reservation = $this->booking($resident, $facility);
        $use = DB::transaction(fn () => OfficialUseScheduling::create($facility, ['date' => $reservation->reservation_date, 'start_time' => '13:00', 'end_time' => '15:00', 'purpose' => 'Barangay program'], $admin));
        $conflict = $use->conflicts()->firstOrFail();
        $this->actingAs($resident);
        $this->renderFixture('resident-conflicts', $this->get(route('reservations.index')));
        $this->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson(route('official-use-conflicts.decision', $conflict), ['decision' => 'cancel'])->assertOk()->assertJsonPath('success', true);
        $this->renderFixture('resident-decision', $this->get(route('reservations.index')));
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertSame('100.00', $reservation->total_payment);
        $this->assertSame('cancellation_requested', $conflict->fresh()->resolution);
    }
}
