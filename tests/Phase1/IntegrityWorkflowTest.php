<?php

namespace Tests\Phase1;

use App\Models\Facility;
use App\Models\OfficialUse;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class Phase1ProbeJob implements ShouldQueue
{
    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}

final class IntegrityWorkflowTest extends TestCase
{
    private User $admin;

    private User $resident;

    private Facility $facility;

    public function createApplication()
    {
        return isolatedApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(17, DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('ENGINE', 'InnoDB')->count());
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function () {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 21)->setTime(9, 0));
        $this->admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
        $this->resident = User::factory()->create(['barangay' => 'Taft', 'username' => 'phase1.resident']);
        $this->facility = Facility::create(['barangay' => 'Taft', 'slug' => 'phase1-'.Str::random(8), 'name' => 'Phase 1 test resource',
            'category' => 'Facility', 'description' => 'Synthetic', 'capacity' => 50, 'location' => 'Taft', 'status' => 'Available', 'hourly_rate' => '100.00']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['reservation_date' => '2026-10-23', 'start_time' => '08:00', 'end_time' => '10:00', 'purpose' => 'Synthetic Phase 1 booking', 'attendees' => 10], $overrides);
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::create(array_replace($this->data(), ['user_id' => $this->resident->id, 'facility_id' => $this->facility->id,
            'barangay' => 'Taft', 'facility_slug' => $this->facility->slug, 'facility_name' => $this->facility->name, 'category' => $this->facility->category,
            'location' => 'Taft', 'status' => 'pending', 'hourly_rate_snapshot' => '100.00'], $overrides));
    }

    private function rejects(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL integrity rejection.');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlCode, $exception->errorInfo[1]);
        }
    }

    public function test_booking_payment_cancellation_and_refund_preserve_history(): void
    {
        $this->actingAs($this->resident)->postJson(route('reservations.store', $this->facility->slug), $this->data())->assertCreated();
        $reservation = Reservation::where('facility_id', $this->facility->id)->sole();
        $this->assertSame('pending', $reservation->status);
        $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), ['total_payment' => '200.00', 'payment_confirmed' => 1])->assertOk();
        $payment = $reservation->fresh()->payment;
        $this->assertSame('paid', $payment->payment_status);
        $this->assertSame('200.00', $payment->amount);
        $this->patchJson(route('reservations.edit-accepted', $reservation), ['action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'User Requested Cancellation'])->assertOk();
        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertNotEmpty($reservation->fresh()->change_history);
        $this->assertSame('refund_pending', $payment->fresh()->payment_status);
        $this->patchJson(route('payments.update', $payment), ['payment_status' => 'refunded', 'receipt_confirmed' => 1])->assertOk();
        $this->assertSame('refunded', $payment->fresh()->payment_status);
        $this->assertSame('200.00', $payment->fresh()->amount);
        $this->assertSame('200.00', $reservation->fresh()->total_payment);
        $this->rejects(fn () => DB::table('reservations')->where('id', $reservation->id)->delete(), 1451);
    }

    public function test_equipment_allocation_overbooking_and_cancellation(): void
    {
        $this->facility->update(['category' => 'Equipment', 'capacity' => null, 'total_quantity' => 10]);
        $this->actingAs($this->resident)->postJson(route('reservations.store', $this->facility->slug), $this->data(['quantity' => 6]))->assertCreated();
        $reservation = Reservation::where('facility_id', $this->facility->id)->sole();
        $this->actingAs($this->admin)->postJson(route('reservations.accept', $reservation), ['total_payment' => '200.00', 'payment_confirmed' => 1])->assertOk();
        $this->assertSame(4, ReservationAvailability::availableQuantity($this->facility->fresh(), $reservation->period()));
        $other = User::factory()->create(['barangay' => 'Taft']);
        $this->actingAs($other)->postJson(route('reservations.store', $this->facility->slug), $this->data(['quantity' => 5]))->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson(route('reservations.edit-accepted', $reservation), ['action' => 'cancel', 'cancellation_confirmed' => 1, 'cancellation_reason' => 'User Requested Cancellation'])->assertOk();
        $this->assertSame(10, ReservationAvailability::availableQuantity($this->facility->fresh(), $reservation->period()));
    }

    public function test_linked_and_nullable_legacy_reservation_history_blocks_resource_deletion(): void
    {
        $reservation = $this->reservation(['status' => 'cancelled']);
        $photo = $this->facility->photos()->create(['path' => 'synthetic.jpg', 'sort_order' => 0]);
        $this->actingAs($this->admin)->deleteJson(route('facilities.destroy', $this->facility->slug))->assertUnprocessable()->assertJsonValidationErrors('facility');
        $this->rejects(fn () => DB::table('facilities')->where('id', $this->facility->id)->delete(), 1451);
        $this->rejects(fn () => DB::table('users')->where('id', $this->resident->id)->delete(), 1451);
        $reservation->update(['facility_id' => null]);
        $this->deleteJson(route('facilities.destroy', $this->facility->slug))->assertUnprocessable();
        $this->assertDatabaseHas('facility_photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'facility_id' => null, 'facility_name' => $this->facility->name]);
    }

    public function test_unavailable_resource_keeps_history_and_blocks_booking(): void
    {
        $reservation = $this->reservation(['status' => 'cancelled']);
        $data = $this->facility->only(['name', 'category', 'description', 'location', 'capacity']);
        $this->actingAs($this->admin)->patchJson(route('facilities.update', $this->facility->slug), [...$data, 'status' => 'Unavailable'])->assertOk();
        $this->actingAs($this->resident)->postJson(route('reservations.store', $this->facility->slug), $this->data())->assertUnprocessable();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'cancelled']);
    }

    public function test_official_use_and_conflict_history_prevent_parent_deletion(): void
    {
        $reservation = $this->reservation();
        $use = OfficialUse::create(['facility_id' => $this->facility->id, 'barangay' => 'Taft', 'date' => '2026-10-23', 'start_time' => '08:00', 'end_time' => '10:00', 'purpose' => 'Synthetic history', 'status' => 'cancelled', 'created_by' => $this->admin->id]);
        DB::table('official_use_conflicts')->insert(['official_use_id' => $use->id, 'reservation_id' => $reservation->id, 'resolution' => 'resolved']);
        $this->actingAs($this->admin)->deleteJson(route('facilities.destroy', $this->facility->slug))->assertUnprocessable();
        $this->rejects(fn () => DB::table('facilities')->where('id', $this->facility->id)->delete(), 1451);
        $this->rejects(fn () => DB::table('official_uses')->where('id', $use->id)->delete(), 1451);
        $this->rejects(fn () => DB::table('reservations')->where('id', $reservation->id)->delete(), 1451);
    }

    public function test_all_four_repaired_foreign_keys_reject_invalid_references(): void
    {
        $reservation = $this->reservation();
        $photo = $this->facility->photos()->create(['path' => 'synthetic.jpg', 'sort_order' => 0]);
        $challenge = (string) Str::uuid();
        DB::table('two_factor_challenges')->insert(['id' => $challenge, 'user_id' => $this->resident->id, 'purpose' => 'login', 'method' => 'email', 'destination' => 'test@example.invalid', 'code_hash' => 'synthetic', 'binding_hash' => 'synthetic', 'context_hash' => 'synthetic', 'attempts' => 0, 'expires_at' => now()->addMinutes(5)]);
        foreach ([['reservations', 'user_id', $reservation->id], ['reservations', 'facility_id', $reservation->id], ['facility_photos', 'facility_id', $photo->id], ['two_factor_challenges', 'user_id', $challenge]] as [$table, $column, $id]) {
            $this->rejects(fn () => DB::table($table)->where('id', $id)->update([$column => 4294967295]), 1452);
        }
        // Only transient challenges cascade; deleting a user with reservations is rejected above.
        $other = User::factory()->create();
        DB::table('two_factor_challenges')->where('id', $challenge)->update(['user_id' => $other->id]);
        $other->delete();
        $this->assertDatabaseMissing('two_factor_challenges', ['id' => $challenge]);
    }

    public function test_unused_resource_can_be_deleted_with_its_photos(): void
    {
        $photo = $this->facility->photos()->create(['path' => 'synthetic.jpg', 'sort_order' => 0]);
        $this->actingAs($this->admin)->deleteJson(route('facilities.destroy', $this->facility->slug))->assertOk();
        $this->assertDatabaseMissing('facilities', ['id' => $this->facility->id]);
        $this->assertDatabaseMissing('facility_photos', ['id' => $photo->id]);
    }

    public function test_authentication_uses_the_isolated_users_table(): void
    {
        $this->post(route('login.store'), ['email' => 'phase1.resident', 'password' => 'incorrect'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => 'phase1.resident', 'password' => 'password'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($this->resident);
    }

    public function test_only_synthetic_queue_job_is_reserved_released_and_executed(): void
    {
        $original = DB::table('jobs')->orderBy('id')->get()->toJson();
        $queueName = 'phase1_'.Str::random(20);
        $queue = Queue::connection('phase1');
        $id = $queue->push(new Phase1ProbeJob, '', $queueName);
        $job = $queue->pop($queueName);
        $this->assertSame((string) $id, (string) $job->getJobId());
        $job->release(0);
        $job = $queue->pop($queueName);
        $this->assertSame(2, $job->attempts());
        Phase1ProbeJob::$handled = 0;
        $job->fire();
        $job->delete();
        $this->assertSame(1, Phase1ProbeJob::$handled);
        $this->assertNull($queue->pop($queueName));
        $this->assertSame($original, DB::table('jobs')->orderBy('id')->get()->toJson());
        Mail::raw('Synthetic message', fn ($message) => $message->to('nobody@example.invalid')->subject('Phase 1'));
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }
}
