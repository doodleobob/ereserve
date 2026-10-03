<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();
                // Preserve the legacy snapshot design; reports read reservations.total_payment.
                $table->decimal('amount', 12, 2);
                $table->enum('payment_status', ['pending', 'paid', 'refunded', 'cancelled'])->default('pending');
                $table->timestamp('refunded_at')->nullable();
                $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('revision')->default(0);
                $table->json('history')->nullable();
                $table->timestamps();
                $table->index(['payment_status', 'created_at']);
            });
        } else {
            foreach (['reservation_id', 'amount', 'payment_status', 'refunded_at', 'refunded_by', 'revision', 'history', 'created_at', 'updated_at'] as $column) {
                if (! Schema::hasColumn('payments', $column)) {
                    throw new RuntimeException('Unrecognized Payment schema; existing history was not replaced.');
                }
            }
            if (DB::getDriverName() === 'mysql') {
                $engine = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())->where('table_name', 'payments')->value('engine');
                if (strtoupper((string) $engine) === 'MYISAM') {
                    DB::statement('ALTER TABLE `payments` ENGINE=InnoDB');
                }
            }
        }
        if (! collect(Schema::getIndexes('payments'))->contains(fn ($index) => $index['columns'] === ['payment_status', 'created_at'])) {
            Schema::table('payments', fn (Blueprint $table) => $table->index(['payment_status', 'created_at']));
        }
        // Acceptance requires confirmed receipt. Do not infer payment from cancelled/pending requests.
        DB::transaction(function () {
            DB::table('reservations')->where('status', 'accepted')->whereNotNull('total_payment')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments')->whereColumn('payments.reservation_id', 'reservations.id'))
                ->orderBy('id')->each(function ($reservation) {
                    DB::table('payments')->insert([
                        'reservation_id' => $reservation->id, 'amount' => $reservation->total_payment, 'payment_status' => 'paid',
                        'revision' => 0, 'created_at' => $reservation->updated_at ?? $reservation->created_at,
                        'updated_at' => $reservation->updated_at ?? $reservation->created_at,
                    ]);
                });
        });
    }

    public function down(): void
    {
        // Financial records and the repaired storage engine are deliberately retained on rollback.
    }
};
