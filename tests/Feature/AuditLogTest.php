<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_active_super_admin_can_filter_and_view_read_only_events_across_barangays(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $entry = AuditLogEntry::create([
            'actor_id' => $admin->id, 'actor_name' => $admin->name, 'actor_role' => 'admin', 'action' => 'admin.status_changed',
            'target_type' => 'User', 'target_id' => '999', 'barangay' => 'Washington',
            'description' => 'Changed a Barangay Admin account status', 'before_values' => ['is_active' => true],
            'after_values' => ['is_active' => false], 'occurred_at' => now(),
        ]);

        $this->actingAs($super)->get(route('super-admin.audit-logs.index', ['barangay' => 'Washington', 'actor_role' => 'admin']))
            ->assertOk()->assertSee($admin->name)->assertSee('Washington')->assertSee('Changed a Barangay Admin account status')
            ->assertSee('management-table-scroll', false)->assertSee('reservation-table-pagination', false)
            ->assertSee('View details')->assertSee('Actor role')->assertSee('Barangay');
        $this->get(route('super-admin.audit-logs.show', $entry))->assertOk()->assertSee('is_active')->assertSee('false');
        $this->patch(route('super-admin.audit-logs.show', $entry), [])->assertMethodNotAllowed();
        $this->delete(route('super-admin.audit-logs.show', $entry))->assertMethodNotAllowed();
        $this->assertDatabaseHas('audit_log_entries', ['id' => $entry->id]);
    }

    public function test_audit_log_filters_and_pagination_are_applied_to_server_queries(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington', 'name' => 'Filterable Admin']);
        foreach (range(1, 30) as $index) {
            AuditLogEntry::create([
                'actor_id' => $admin->id, 'actor_name' => $admin->name, 'actor_role' => 'admin',
                'action' => $index === 30 ? 'admin.updated' : 'admin.status_changed',
                'target_type' => 'User', 'target_id' => (string) $index, 'barangay' => $admin->barangay,
                'description' => $index === 30 ? 'Updated a Barangay Admin account' : 'Changed a Barangay Admin account status',
                'occurred_at' => now()->subMinutes($index),
            ]);
        }

        $this->actingAs($super)->get(route('super-admin.audit-logs.index'))
            ->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 30 && $entries->count() === 25 && $entries->lastPage() === 2);
        $this->get(route('super-admin.audit-logs.index', ['page' => 2]))
            ->assertViewHas('entries', fn ($entries) => $entries->currentPage() === 2 && $entries->count() === 5);
        $this->get(route('super-admin.audit-logs.index', ['barangay' => 'Washington', 'actor_role' => 'admin', 'action' => 'admin.updated', 'search' => 'Filterable']))
            ->assertViewHas('entries', fn ($entries) => $entries->total() === 1 && $entries->first()->action === 'admin.updated');
    }

    public function test_non_super_admin_and_inactive_super_admin_cannot_read_audit_log(): void
    {
        foreach ([
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'user']),
        ] as $actor) {
            $this->actingAs($actor)->get(route('super-admin.audit-logs.index'))->assertForbidden();
        }

        $inactive = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
        $this->actingAs($inactive)->get(route('super-admin.audit-logs.index'))->assertRedirect(route('login'));
    }

    public function test_admin_account_status_change_is_audited_atomically_and_actor_is_resolved_from_session(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);

        $this->actingAs($super)->patch(route('admins.status', $admin), ['is_active' => false]);

        $this->assertDatabaseHas('audit_log_entries', [
            'actor_id' => $super->id, 'actor_role' => 'super_admin', 'action' => 'admin.status_changed',
            'target_type' => 'User', 'target_id' => (string) $admin->id, 'barangay' => 'Washington',
        ]);
        $entry = AuditLogEntry::where('action', 'admin.status_changed')->firstOrFail();
        $this->assertSame(['is_active' => true], $entry->before_values);
        $this->assertSame(['is_active' => false], $entry->after_values);
    }

    public function test_logger_redacts_sensitive_and_free_text_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($admin);

        app(AuditLogger::class)->record('admin.status_changed', $admin,
            ['password' => 'never-store', 'is_active' => true, 'notes' => 'private notes'],
            ['is_active' => false, 'email' => 'private@example.test', 'token' => 'secret']);

        $entry = AuditLogEntry::firstOrFail();
        $this->assertSame(['is_active' => true], $entry->before_values);
        $this->assertSame(['is_active' => false], $entry->after_values);
        $this->assertStringNotContainsString('never-store', json_encode($entry->getAttributes()));
        $this->assertStringNotContainsString('private@example.test', json_encode($entry->getAttributes()));
    }

    public function test_log_keeps_actor_snapshot_and_target_identifier_after_account_deletion(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->actingAs($admin);
        app(AuditLogger::class)->record('admin.status_changed', $admin, ['is_active' => true], ['is_active' => false]);
        $entry = AuditLogEntry::firstOrFail();

        $admin->delete();

        $entry->refresh();
        $this->assertNull($entry->actor_id);
        $this->assertSame($admin->name, $entry->actor_name);
        $this->assertSame((string) $admin->id, $entry->target_id);
        $this->assertDatabaseHas('audit_log_entries', ['id' => $entry->id, 'actor_name' => $admin->name]);
    }

    public function test_actor_and_barangay_context_use_persisted_accounts(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'barangay' => 'Taft']);
        $target = User::factory()->create(['role' => 'admin', 'barangay' => 'Washington']);
        $this->actingAs($actor);
        $forgedTarget = clone $target;
        $forgedTarget->barangay = 'Taft';

        app(AuditLogger::class)->record('admin.status_changed', $forgedTarget, ['is_active' => true], ['is_active' => false]);

        $this->assertDatabaseHas('audit_log_entries', [
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'actor_role' => 'super_admin',
            'target_id' => (string) $target->id, 'barangay' => 'Washington',
        ]);
    }

    public function test_rolled_back_business_transaction_also_rolls_back_its_audit_entry(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor);

        try {
            DB::transaction(function () use ($target): void {
                app(AuditLogger::class)->record('admin.status_changed', $target, ['is_active' => true], ['is_active' => false]);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_log_entries', 0);
    }
}
