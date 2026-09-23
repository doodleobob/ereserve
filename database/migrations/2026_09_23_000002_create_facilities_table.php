<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facilities')) {
            Schema::create('facilities', function (Blueprint $table) {
                $table->id();
                $table->string('barangay', 120)->index();
                $table->string('slug', 120);
                $table->string('name', 160);
                $table->string('category', 40);
                $table->text('description');
                $table->unsignedInteger('capacity');
                $table->string('location', 160);
                $table->string('status', 30)->default('Available');
                $table->timestamps();

                $table->unique('slug');
                $table->index(['barangay', 'slug']);
            });
        }

        if (! Schema::hasColumn('reservations', 'facility_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->foreignId('facility_id')
                    ->nullable()
                    ->after('barangay')
                    ->constrained()
                    ->nullOnDelete();

                $table->index(['facility_id', 'reservation_date', 'status', 'start_time', 'end_time'], 'reservations_facility_id_availability_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservations', 'facility_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropIndex('reservations_facility_id_availability_index');
                $table->dropConstrainedForeignId('facility_id');
            });
        }

        Schema::dropIfExists('facilities');
    }
};
