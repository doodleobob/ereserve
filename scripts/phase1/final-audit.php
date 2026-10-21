<?php

require __DIR__.'/common.php';
$directory = realpath($argv[1] ?? '');
if (! $directory || ! is_file($directory.'/repairs-verified.json')) {
    throw new RuntimeException('Verified repair artifacts are required.');
}
$testConfig = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);
$test = connect($testConfig);
assertIsolated($test, $testConfig);
$baseline = json_decode(file_get_contents($directory.'/baseline.json'), true, flags: JSON_THROW_ON_ERROR);
$afterRepairs = json_decode(file_get_contents($directory.'/after-repairs.json'), true, flags: JSON_THROW_ON_ERROR);
$final = snapshot($test, array_keys($baseline));
foreach ($final as $table => $actual) {
    foreach (['count', 'sha256', 'columns', 'indexes'] as $field) {
        if ($actual[$field] !== $afterRepairs[$table][$field]) {
            throw new RuntimeException('Post-test preservation failed: '.$table.'.'.$field);
        }
    }
}
$env = Dotenv\Dotenv::createArrayBacked(__DIR__.'/../../')->load();
if (! empty($env['DB_URL']) || ($env['DB_DATABASE'] ?? '') !== 'ereserve') {
    throw new RuntimeException('Unexpected source configuration.');
}
$source = connect(['host' => $env['DB_HOST'] ?? '127.0.0.1', 'port' => $env['DB_PORT'] ?? 3306, 'database' => 'ereserve', 'username' => $env['DB_USERNAME'], 'password' => $env['DB_PASSWORD']]);
$source->exec('SET SESSION lock_wait_timeout=15');
$source->exec('SET autocommit=0');
$source->exec('LOCK TABLES '.implode(',', array_map(fn ($t) => identifier($t).' READ', array_keys($baseline))));
try {
    $sourceAfter = snapshot($source, array_keys($baseline));
} finally {
    $source->exec('UNLOCK TABLES');
    $source->exec('SET autocommit=1');
}
$sourceDifferences = [];
foreach ($baseline as $table => $expected) {
    foreach ($expected as $field => $value) {
        if ($value !== $sourceAfter[$table][$field]) {
            $sourceDifferences[] = $table.'.'.$field;
        }
    }
}
$engines = $test->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_KEY_PAIR);
if (count($engines) !== 17 || array_unique(array_values($engines)) !== ['InnoDB']) {
    throw new RuntimeException('Final engine inventory mismatch.');
}
$report = ['test_records_preserved_after_workflows' => true, 'restored_jobs_unchanged' => true,
    'test_table_count' => count($engines), 'all_test_tables_innodb' => true,
    'working_database_unchanged' => $sourceDifferences === [], 'working_differences' => $sourceDifferences,
    'instance_default_engine' => $source->query('SELECT @@global.default_storage_engine')->fetchColumn(),
    'preserved_row_counts' => array_map(fn ($t) => $t['count'], $baseline)];
exclusiveJson($directory.'/final-audit.json', $report);
echo json_encode($report, JSON_PRETTY_PRINT).PHP_EOL;
if ($sourceDifferences) {
    exit(1);
}
