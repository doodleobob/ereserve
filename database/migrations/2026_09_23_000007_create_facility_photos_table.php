<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['facility_id', 'sort_order']);
        });

        if (Schema::hasColumn('facilities', 'photo_path')) {
            $now = now();

            DB::table('facilities')
                ->whereNotNull('photo_path')
                ->where('photo_path', '!=', '')
                ->orderBy('id')
                ->each(function (object $facility) use ($now): void {
                    DB::table('facility_photos')->insert([
                        'facility_id' => $facility->id,
                        'path' => $facility->photo_path,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_photos');
    }
};
