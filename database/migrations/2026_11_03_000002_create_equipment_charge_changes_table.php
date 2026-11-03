<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_charge_changes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('equipment_charge_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->json('before_values');
            $table->json('after_values');
            $table->timestamp('created_at');
            $table->index(['equipment_charge_id', 'id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('equipment_charge_changes') && \Illuminate\Support\Facades\DB::table('equipment_charge_changes')->exists()) {
            throw new RuntimeException('Equipment charge history exists. Archive and review it before removing this table.');
        }
        Schema::dropIfExists('equipment_charge_changes');
    }
};
