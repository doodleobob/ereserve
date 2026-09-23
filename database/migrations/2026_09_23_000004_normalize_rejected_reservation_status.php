<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reservations')
            ->where('status', 'declined')
            ->update(['status' => 'rejected']);
    }

    public function down(): void
    {
        DB::table('reservations')
            ->where('status', 'rejected')
            ->update(['status' => 'declined']);
    }
};
