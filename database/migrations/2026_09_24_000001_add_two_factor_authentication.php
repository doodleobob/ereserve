<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->nullable();
            $table->text('phone_number')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
        });

        Schema::create('two_factor_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 10);
            $table->string('method', 10);
            $table->text('destination');
            $table->string('code_hash');
            $table->string('binding_hash', 64);
            $table->string('context_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->unique(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_method', 'phone_number', 'phone_verified_at']);
        });
    }
};
