<?php

use App\Support\Barangays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'barangay')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('barangay')->default(Barangays::DEFAULT)->after('email');
            });
        }

        DB::table('users')->where('role', 'resident')->update(['role' => 'user']);
        DB::table('users')->whereNull('barangay')->update(['barangay' => Barangays::DEFAULT]);

        if (! Schema::hasColumn('reservations', 'barangay')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->string('barangay')->default(Barangays::DEFAULT)->after('user_id')->index();
            });
        }

        DB::table('reservations')->whereNull('barangay')->update(['barangay' => Barangays::DEFAULT]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservations', 'barangay')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropColumn('barangay');
            });
        }

        if (Schema::hasColumn('users', 'barangay')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('barangay');
            });
        }
    }
};
