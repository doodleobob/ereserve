<?php

// Read-only deployment audit: aggregate counts and fingerprints, never resident details.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$result = ['driver' => DB::getDriverName()];
foreach (['facilities', 'reservations', 'official_uses', 'payments', 'official_use_conflicts', 'notifications'] as $table) {
    if (! Schema::hasTable($table)) {
        continue;
    }
    $columns = array_values(array_diff(Schema::getColumnListing($table), ['quantity', 'total_quantity']));
    $hash = hash_init('sha256');
    $count = 0;
    foreach (DB::table($table)->select($columns)->orderBy('id')->cursor() as $row) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
        $count++;
    }
    $result['preserved_records'][$table] = ['count' => $count, 'sha256' => hash_final($hash)];
}
$result['equipment_resources'] = DB::table('facilities')->where('category', 'Equipment')->count();
$result['equipment_bookings_by_status'] = DB::table('reservations')->where('category', 'Equipment')
    ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
$result['quantity_migration_applied'] = Schema::hasColumn('facilities', 'total_quantity');
if ($result['quantity_migration_applied']) {
    $result['equipment_stock_unverified'] = DB::table('facilities')->where('category', 'Equipment')->whereNull('total_quantity')->count();
    $result['legacy_equipment_bookings'] = DB::table('reservations')->where('category', 'Equipment')->whereNull('quantity')->count();
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
