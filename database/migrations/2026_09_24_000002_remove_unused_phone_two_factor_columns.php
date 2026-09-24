<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These fields belonged exclusively to the abandoned SMS feature.
        // Refuse to discard data if another installation has actually used it.
        if (DB::table('users')->whereNotNull('phone_number')->orWhereNotNull('phone_verified_at')->exists()
            || DB::table('users')->where('two_factor_method', 'phone')->exists()
            || DB::table('two_factor_challenges')->where('method', 'phone')->exists()) {
            throw new RuntimeException('Phone data exists; preserve and review it before removing the unused columns.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'phone_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('phone_number')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
        });
    }
};
