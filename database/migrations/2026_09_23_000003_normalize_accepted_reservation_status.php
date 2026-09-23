<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reservations')
            ->where('status', 'approved')
            ->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        DB::table('reservations')
            ->where('status', 'accepted')
            ->update(['status' => 'approved']);
    }
};
