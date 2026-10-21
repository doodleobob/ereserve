<?php

use App\Support\DatabaseIntegrityRepair;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/isolated-app.php';
$directory = realpath($argv[1] ?? '');
putenv('PHASE1_ARTIFACT_DIR='.$directory);
isolatedApplication();
failOnException();
$checks = [];
$parent = 'phase1_probe_parent';
$child = 'phase1_probe_child';
if (Schema::hasTable($parent) || Schema::hasTable($child)) {
    throw new RuntimeException('Probe tables already exist; refusing to overwrite.');
}
try {
    Schema::create($parent, fn (Blueprint $table) => $table->id());
    Schema::create($child, function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable()->index();
    });
    $engine = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $parent)->value('ENGINE');
    if ($engine !== 'InnoDB') {
        throw new RuntimeException('Future table default is not InnoDB.');
    }
    $checks['future_tables_use_innodb'] = true;
    DB::table($child)->insert(['parent_id' => 999]);
    try {
        DatabaseIntegrityRepair::foreignKey($child, 'parent_id', $parent, 'RESTRICT');
        throw new LogicException('Orphaned data was accepted.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Orphaned')) {
            throw $exception;
        }
    }
    if (DB::table($child)->count() !== 1 || Schema::getForeignKeys($child) !== []) {
        throw new RuntimeException('Orphan failure modified data/schema.');
    }
    $checks['orphans_fail_without_data_cleanup'] = true;
    DB::table($parent)->insert(['id' => 999]);
    DatabaseIntegrityRepair::foreignKey($child, 'parent_id', $parent, 'RESTRICT');
    $before = Schema::getForeignKeys($child);
    try {
        DatabaseIntegrityRepair::foreignKey($child, 'parent_id', $parent, 'CASCADE');
        throw new LogicException('Conflicting constraint was accepted.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Conflicting')) {
            throw $exception;
        }
    }
    if ($before !== Schema::getForeignKeys($child)) {
        throw new RuntimeException('Conflicting constraint was modified.');
    }
    $checks['conflicting_constraint_fails_closed'] = true;
} finally {
    Schema::dropIfExists($child);
    Schema::dropIfExists($parent);
}

// Exercise compatibility with the old migration on fresh installations, only on the verified clone.
$before = DB::table('reservations')->orderBy('id')->get()->toJson();
DB::statement('ALTER TABLE reservations DROP FOREIGN KEY reservations_facility_id_foreign, ADD CONSTRAINT reservations_facility_id_legacy_foreign FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL ON UPDATE RESTRICT');
DB::statement('ALTER TABLE reservations DROP FOREIGN KEY reservations_facility_id_legacy_foreign, ADD CONSTRAINT reservations_facility_id_foreign FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL ON UPDATE RESTRICT');
try {
    (require __DIR__.'/../../database/migrations/2026_10_21_000012_repair_reservations_facility_id_foreign_key.php')->up();
    $key = collect(Schema::getForeignKeys('reservations'))->firstWhere('name', 'reservations_facility_id_retained_foreign');
    if ($key['on_delete'] !== 'restrict' || DB::table('reservations')->orderBy('id')->get()->toJson() !== $before) {
        throw new RuntimeException('Legacy SET NULL compatibility check failed.');
    }
    $checks['legacy_set_null_strengthened_without_record_changes'] = true;
    (require __DIR__.'/../../database/migrations/2026_10_21_000012_repair_reservations_facility_id_foreign_key.php')->up();
    $checks['renamed_legacy_constraint_is_idempotent'] = true;
} finally {
    DatabaseIntegrityRepair::foreignKey('reservations', 'facility_id', 'facilities', 'RESTRICT', true);
    if (collect(Schema::getForeignKeys('reservations'))->contains('name', 'reservations_facility_id_retained_foreign')) {
        DB::statement('ALTER TABLE reservations DROP FOREIGN KEY reservations_facility_id_retained_foreign, ADD CONSTRAINT reservations_facility_id_foreign FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
    }
}
exclusiveJson($directory.'/schema-checks.json', $checks);
echo json_encode($checks, JSON_PRETTY_PRINT).PHP_EOL;
