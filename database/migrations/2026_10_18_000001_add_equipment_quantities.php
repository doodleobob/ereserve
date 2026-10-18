<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL means unverified stock / a legacy whole-resource booking, never an inferred unit count.
        Schema::table('facilities', function (Blueprint $table) {
            $table->unsignedInteger('total_quantity')->nullable();
            $table->unsignedInteger('capacity')->nullable()->change();
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('attendees')->nullable()->change();
        });
        Schema::table('official_uses', fn (Blueprint $table) => $table->unsignedInteger('quantity')->nullable());
    }

    public function down(): void
    {
        // Do not erase verified inventory or booking quantities on rollback.
        foreach (['facilities' => 'total_quantity', 'reservations' => 'quantity', 'official_uses' => 'quantity'] as $table => $column) {
            if (DB::table($table)->whereNotNull($column)->exists()) {
                throw new RuntimeException('Cannot remove equipment quantities while recorded values exist. Export and reconcile them before rollback.');
            }
        }
        Schema::table('official_uses', fn (Blueprint $table) => $table->dropColumn('quantity'));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('quantity'));
        Schema::table('facilities', fn (Blueprint $table) => $table->dropColumn('total_quantity'));
        // Keep the relaxed nullability: replacing unknown historical values would lose information.
    }
};
