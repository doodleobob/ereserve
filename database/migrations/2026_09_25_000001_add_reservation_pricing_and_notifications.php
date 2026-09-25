<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->default(0);
        });
        Schema::table('reservations', function (Blueprint $table) {
            // Historical rates were never recorded; do not invent them.
            $table->decimal('hourly_rate_snapshot', 10, 2)->nullable();
            $table->decimal('total_payment', 12, 2)->nullable();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn(['hourly_rate_snapshot', 'total_payment']));
        Schema::table('facilities', fn (Blueprint $table) => $table->dropColumn('hourly_rate'));
    }
};
