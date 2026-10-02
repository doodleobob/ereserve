<?php

use App\Support\ReservationPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->prepareExistingSchema();

        if (! Schema::hasTable('official_uses')) {
            Schema::create('official_uses', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('barangay', 120)->index();
                $table->foreignId('facility_id')->constrained()->restrictOnDelete();
                $table->date('date');
                $table->time('start_time');
                $table->time('end_time');
                $table->text('purpose');
                $table->string('status', 30)->default('active');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['facility_id', 'date', 'status']);
            });
        }
        if (! Schema::hasTable('official_use_conflicts')) {
            Schema::create('official_use_conflicts', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->foreignId('official_use_id')->constrained()->restrictOnDelete();
                $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
                $table->string('resolution', 40)->default('awaiting_user_decision');
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->unique(['official_use_id', 'reservation_id']);
                $table->index(['reservation_id', 'resolution']);
            });
        }
        $this->importLegacySchedules();
    }

    public function down(): void
    {
        Schema::dropIfExists('official_use_conflicts');
        Schema::dropIfExists('official_uses');
        if (Schema::hasTable('legacy_official_uses')) {
            Schema::rename('legacy_official_uses', 'official_uses');
        }
    }

    private function prepareExistingSchema(): void
    {
        if (Schema::hasTable('official_uses') && ! Schema::hasColumn('official_uses', 'date')) {
            foreach (['reservation_date', 'reason', 'state', 'facility_id'] as $column) {
                if (! Schema::hasColumn('official_uses', $column)) {
                    throw new RuntimeException('Unrecognized legacy Official Use schema; no records were replaced.');
                }
            }
            if (Schema::hasTable('legacy_official_uses')) {
                throw new RuntimeException('A legacy Official Use archive already exists; no records were replaced.');
            }
            Schema::rename('official_uses', 'legacy_official_uses');
        }

        // The local legacy schema used MyISAM, which cannot honor transactions or row locks.
        if (DB::getDriverName() === 'mysql') {
            foreach (['users', 'facilities', 'reservations', 'notifications'] as $table) {
                $engine = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->value('engine');
                if (strtoupper((string) $engine) === 'MYISAM') {
                    DB::statement('ALTER TABLE `'.$table.'` ENGINE=InnoDB');
                }
            }
        }

        // Restore the current application's payment field without rewriting old payment history.
        if (! Schema::hasColumn('reservations', 'total_payment')) {
            Schema::table('reservations', fn (Blueprint $table) => $table->decimal('total_payment', 12, 2)->nullable());
        }
        if (Schema::hasTable('payments')) {
            DB::table('payments')->whereIn('payment_status', ['paid', 'refunded'])->orderBy('id')->each(function ($payment) {
                DB::table('reservations')->where('id', $payment->reservation_id)->whereNull('total_payment')->update(['total_payment' => $payment->amount]);
            });
        }
    }

    private function importLegacySchedules(): void
    {
        if (! Schema::hasTable('legacy_official_uses')) {
            return;
        }
        // Keep all original fields/rows in the archive; import schedules and conflict history separately.
        // No historical notification is replayed and no resident/payment record is deleted.
        DB::transaction(function () {
            $legacy = DB::table('legacy_official_uses')->orderBy('id')->get();
            $resources = DB::table('facilities')->get()->keyBy('id');
            $users = DB::table('users')->pluck('id')->all();
            foreach ($legacy->filter(fn ($row) => ($row->schedule_id ?? null) === null && ($row->cancelled_at ?? null) === null) as $schedule) {
                $resource = $resources->get($schedule->facility_id);
                if ($resource === null) {
                    continue; // Orphaned historical rows remain intact in the archive.
                }
                DB::table('official_uses')->updateOrInsert(['id' => $schedule->id], [
                    'barangay' => $resource->barangay, 'facility_id' => $resource->id,
                    'date' => $schedule->reservation_date, 'start_time' => $schedule->start_time, 'end_time' => $schedule->end_time,
                    'purpose' => $schedule->reason, 'status' => 'active',
                    'created_by' => in_array($schedule->initiated_by ?? null, $users, true) ? $schedule->initiated_by : null,
                    'created_at' => $schedule->created_at, 'updated_at' => $schedule->updated_at,
                ]);
                $period = new ReservationPeriod($schedule->reservation_date, $schedule->start_time, $schedule->end_time);
                $history = $legacy->filter(fn ($row) => $row->id === $schedule->id || ($row->schedule_id ?? null) === $schedule->id)->keyBy('reservation_id');
                $reservations = DB::table('reservations')->where(function ($q) use ($resource) {
                    $q->where('facility_id', $resource->id)->orWhere(function ($q) use ($resource) {
                        $q->whereNull('facility_id')->where('facility_slug', $resource->slug)->where('barangay', $resource->barangay);
                    });
                })->get();
                foreach ($reservations as $reservation) {
                    $previous = $history->get($reservation->id);
                    $reservationPeriod = new ReservationPeriod($reservation->reservation_date, $reservation->start_time, $reservation->end_time);
                    $unresolved = $reservation->status === 'accepted' && $reservationPeriod->overlaps($period->start, $period->end);
                    if (! $unresolved && $previous === null) {
                        continue;
                    }
                    $resolution = ! $unresolved ? 'resolved' : match ($previous->resident_decision ?? null) {
                        'reschedule' => 'reschedule_requested',
                        'cancel', 'cancellation' => 'cancellation_requested',
                        default => 'awaiting_user_decision',
                    };
                    DB::table('official_use_conflicts')->updateOrInsert(['official_use_id' => $schedule->id, 'reservation_id' => $reservation->id], [
                        'resolution' => $resolution, 'decided_at' => $previous->choice_submitted_at ?? null,
                        'resolved_at' => $unresolved ? null : ($previous->resolved_at ?? now()),
                        'created_at' => $previous->created_at ?? $schedule->created_at, 'updated_at' => $previous->updated_at ?? $schedule->updated_at,
                    ]);
                }
                DB::table('official_uses')->where('id', $schedule->id)->update(['status' => DB::table('official_use_conflicts')->where('official_use_id', $schedule->id)->where('resolution', '!=', 'resolved')->exists() ? 'conflict' : 'active']);
            }
        });
    }
};
