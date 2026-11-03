<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_charges', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('resource_usage_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 10, 2);
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->enum('status', ['unpaid', 'cancelled', 'waived'])->default('unpaid');
            $table->uuid('request_key')->unique();
            $table->timestamps();
            $table->index(['resource_usage_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_charges');
    }
};
