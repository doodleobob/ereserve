<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facilities') || ! Schema::hasColumn('reservations', 'facility_id')) {
            return;
        }

        DB::table('facilities')
            ->select(['id', 'barangay', 'slug'])
            ->orderBy('id')
            ->each(function ($facility) {
                DB::table('reservations')
                    ->whereNull('facility_id')
                    ->where('barangay', $facility->barangay)
                    ->where('facility_slug', $facility->slug)
                    ->update(['facility_id' => $facility->id]);
            });
    }

    public function down(): void
    {
        // The original facility link cannot be distinguished safely after backfilling.
    }
};
