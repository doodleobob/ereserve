<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_reference', 32)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
        });

        Schema::create('payment_receipt_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
        });

        Schema::create('payment_sms_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('recipient_last_four', 4)->nullable();
            $table->string('provider_uid', 64)->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sms_notifications');
        Schema::dropIfExists('payment_receipt_sequences');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_reference']);
            $table->dropColumn(['receipt_reference', 'paid_at']);
        });
    }
};
