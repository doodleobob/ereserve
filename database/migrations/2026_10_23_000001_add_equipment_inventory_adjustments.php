<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve recorded stock, including NULL. Zero means no damaged units recorded,
        // not a historical assertion about equipment that was never inventoried.
        Schema::table('facilities', fn (Blueprint $table) => $table->unsignedInteger('damaged_quantity')->default(0));
        Schema::create('equipment_inventory_adjustments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('barangay')->index();
            $table->string('adjustment_type', 20);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('total_before')->nullable();
            $table->unsignedInteger('total_after');
            $table->unsignedInteger('damaged_before');
            $table->unsignedInteger('damaged_after');
            $table->text('reason');
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->uuid('request_key')->nullable()->unique();
            $table->timestamp('created_at');
            $table->index(['facility_id', 'id']);
        });
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'inventory_adjustments_no_'.strtolower($operation);
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON equipment_inventory_adjustments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inventory adjustments are immutable'");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON equipment_inventory_adjustments BEGIN SELECT RAISE(ABORT, 'Inventory adjustments are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('equipment_inventory_adjustments')->exists() || DB::table('facilities')->where('damaged_quantity', '>', 0)->exists()) {
            throw new RuntimeException('Inventory history exists. Review and archive it before removing inventory tracking.');
        }
        Schema::dropIfExists('equipment_inventory_adjustments');
        Schema::table('facilities', fn (Blueprint $table) => $table->dropColumn('damaged_quantity'));
    }
};
