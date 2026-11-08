<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 191);
            $table->string('actor_role', 32);
            $table->string('action', 100)->index();
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 64)->nullable();
            // Keep the (barangay, occurred_at) index under older InnoDB utf8mb4 key limits.
            $table->string('barangay', 180)->nullable();
            $table->string('description', 255);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['actor_role', 'occurred_at']);
            $table->index(['barangay', 'occurred_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_entries');
    }
};
