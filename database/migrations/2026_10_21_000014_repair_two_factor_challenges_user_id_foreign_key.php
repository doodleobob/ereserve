<?php

use App\Support\DatabaseIntegrityRepair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DatabaseIntegrityRepair::foreignKey('two_factor_challenges', 'user_id', 'users', 'CASCADE');
    }

    public function down(): void
    {
        throw new RuntimeException('This integrity repair is forward-only; use a separately reviewed recovery procedure.');
    }
};
