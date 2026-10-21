<?php

use App\Support\DatabaseIntegrityRepair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DatabaseIntegrityRepair::innodb('migrations');
    }

    public function down(): void
    {
        throw new RuntimeException('This integrity repair is forward-only; use a separately reviewed recovery procedure.');
    }
};
