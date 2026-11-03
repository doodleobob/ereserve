<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_charges', function (Blueprint $table) {
            $table->enum('status', ['unpaid', 'paid', 'cancelled', 'waived'])->default('unpaid')->change();
        });

        Schema::create('equipment_charge_payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('equipment_charge_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('payment_date');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->timestamps();
            $table->index(['equipment_charge_id', 'payment_date']);
        });

    }

    public function down(): void
    {
        if (DB::table('equipment_charge_payments')->exists()) {
            throw new RuntimeException('Equipment charge payment history exists. Archive and review it before removing this table.');
        }
        Schema::dropIfExists('equipment_charge_payments');
        Schema::table('equipment_charges', function (Blueprint $table) {
            $table->enum('status', ['unpaid', 'cancelled', 'waived'])->default('unpaid')->change();
        });
    }
};
