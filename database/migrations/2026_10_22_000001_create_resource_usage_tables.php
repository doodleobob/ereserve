<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_usages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('barangay')->index();
            $table->enum('resource_type', ['Facility', 'Equipment']);
            $table->unsignedInteger('approved_quantity')->nullable();
            $table->unsignedInteger('released_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamp('actual_started_at')->nullable();
            $table->timestamp('actual_ended_at')->nullable();
            $table->timestamps();
            $table->index(['facility_id', 'actual_ended_at']);
        });
        Schema::create('resource_usage_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('resource_usage_id')->constrained()->restrictOnDelete();
            $table->enum('event_type', ['check_in', 'check_out', 'release', 'return']);
            $table->unsignedInteger('quantity')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->text('notes')->nullable();
            $table->index(['resource_usage_id', 'id']);
        });

        $invariant = 'released_quantity >= 0 AND returned_quantity >= 0 AND returned_quantity <= released_quantity AND (actual_ended_at IS NULL OR (actual_started_at IS NOT NULL AND actual_ended_at >= actual_started_at)) AND ((resource_type = \'Equipment\' AND approved_quantity IS NOT NULL AND approved_quantity > 0 AND released_quantity <= approved_quantity) OR (resource_type = \'Facility\' AND approved_quantity IS NULL AND released_quantity = 0 AND returned_quantity = 0))';
        $eventInvariant = "((event_type IN ('release', 'return') AND quantity IS NOT NULL AND quantity > 0) OR (event_type IN ('check_in', 'check_out') AND quantity IS NULL))";
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE resource_usages ADD CONSTRAINT usage_quantities_valid CHECK ('.$invariant.')');
            DB::statement('ALTER TABLE resource_usage_events ADD CONSTRAINT usage_event_quantity_valid CHECK ('.$eventInvariant.')');
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER usage_events_no_'.strtolower($operation).' BEFORE '.$operation." ON resource_usage_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Usage events are immutable'");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $condition = preg_replace('/\b(released_quantity|returned_quantity|approved_quantity|resource_type|actual_started_at|actual_ended_at)\b/', 'NEW.$1', $invariant);
                DB::unprepared('CREATE TRIGGER usage_valid_'.strtolower($operation).' BEFORE '.$operation.' ON resource_usages WHEN NOT ('.$condition.") BEGIN SELECT RAISE(ABORT, 'Invalid usage quantities'); END");
            }
            DB::unprepared("CREATE TRIGGER usage_event_valid BEFORE INSERT ON resource_usage_events WHEN NOT (((NEW.event_type IN ('release', 'return')) AND NEW.quantity IS NOT NULL AND NEW.quantity > 0) OR (NEW.event_type IN ('check_in', 'check_out') AND NEW.quantity IS NULL)) BEGIN SELECT RAISE(ABORT, 'Invalid event quantity'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER usage_events_no_'.strtolower($operation).' BEFORE '.$operation." ON resource_usage_events BEGIN SELECT RAISE(ABORT, 'Usage events are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('resource_usages')->exists()) {
            throw new RuntimeException('Usage history exists. Archive and review it before removing this module.');
        }
        Schema::dropIfExists('resource_usage_events');
        Schema::dropIfExists('resource_usages');
    }
};
