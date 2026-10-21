<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/isolated-app.php';
$directory = realpath($argv[1] ?? '');
putenv('PHASE1_ARTIFACT_DIR='.$directory);
$app = isolatedApplication();
failOnException();
$pdo = DB::connection()->getPdo();
$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
$baseline = json_decode(file_get_contents($directory.'/baseline.json'), true, flags: JSON_THROW_ON_ERROR);
$tables = array_keys($baseline);
$before = snapshot($pdo, $tables);
if ($before !== $baseline) {
    throw new RuntimeException('Test database has changed since verified restore.');
}
$paths = glob(__DIR__.'/../../database/migrations/2026_10_21_*.php');
sort($paths);
foreach ($paths as $path) {
    $exit = Artisan::call('migrate', ['--path' => realpath($path), '--realpath' => true, '--force' => true]);
    echo Artisan::output();
    if ($exit !== 0) {
        throw new RuntimeException('Repair migration failed.');
    }
}
$after = snapshot($pdo, $tables);
foreach ($baseline as $table => $expected) {
    if ($table !== 'migrations' && ($expected['count'] !== $after[$table]['count'] || $expected['sha256'] !== $after[$table]['sha256'])) {
        throw new RuntimeException('Data changed: '.$table);
    }
    if ($expected['columns'] !== $after[$table]['columns'] || $expected['indexes'] !== $after[$table]['indexes']) {
        throw new RuntimeException('Columns or existing indexes changed: '.$table);
    }
}
$originalMigrations = $pdo->query('SELECT * FROM migrations ORDER BY id LIMIT '.(int) $baseline['migrations']['count'])->fetchAll(PDO::FETCH_ASSOC);
$hash = hash_init('sha256');
foreach ($originalMigrations as $row) {
    hash_update($hash, serialize($row));
}
if (hash_final($hash) !== $baseline['migrations']['sha256'] || $after['migrations']['count'] !== $baseline['migrations']['count'] + count($paths)) {
    throw new RuntimeException('Migration ledger preservation failed.');
}
$engines = $pdo->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_KEY_PAIR);
if (count($engines) !== 17 || array_unique(array_values($engines)) !== ['InnoDB']) {
    throw new RuntimeException('Not all 17 tables use InnoDB.');
}
// Re-enter every up() method, rather than merely relying on the migration ledger.
foreach ($paths as $path) {
    (require $path)->up();
}
if (snapshot($pdo, $tables) !== $after) {
    throw new RuntimeException('Rerunning repairs changed the already-correct state.');
}
$keys = [];
foreach (['reservations', 'facility_photos', 'two_factor_challenges', 'payments', 'official_uses', 'official_use_conflicts'] as $table) {
    $keys[$table] = Schema::getForeignKeys($table);
}
exclusiveJson($directory.'/repairs-verified.json', ['engines' => $engines, 'foreign_keys' => $keys, 'records_preserved' => true, 'columns_indexes_preserved' => true, 'migration_ledger_preserved' => true, 'idempotent' => true]);
exclusiveJson($directory.'/after-repairs.json', $after);
echo "All 17 engines, row fingerprints, column/index definitions, ledger preservation and migration re-entry verified.\n";
