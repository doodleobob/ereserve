<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('official_use_conflicts', fn (Blueprint $table) => $table->json('resolution_history')->nullable());
    }

    public function down(): void
    {
        Schema::table('official_use_conflicts', fn (Blueprint $table) => $table->dropColumn('resolution_history'));
    }
};
