<?php

require __DIR__.'/common.php';
$env = Dotenv\Dotenv::createArrayBacked(__DIR__.'/../../')->load();
if (! empty($env['DB_URL']) || ($env['DB_DATABASE'] ?? '') !== 'ereserve') {
    throw new RuntimeException('Unexpected source configuration.');
}
$sourceConfig = ['host' => $env['DB_HOST'] ?? '127.0.0.1', 'port' => $env['DB_PORT'] ?? 3306, 'database' => 'ereserve', 'username' => $env['DB_USERNAME'], 'password' => $env['DB_PASSWORD']];
$source = connect($sourceConfig);
$suffix = gmdate('YmdHis').'_'.bin2hex(random_bytes(4));
$directory = __DIR__.'/../../storage/app/phase1/'.$suffix;
if (! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create unique artifact directory.');
}
$bin = $argv[1] ?? 'C:/wamp64/bin/mysql/mysql9.1.0/bin';
$tables = $source->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='ereserve' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
if (count($tables) !== 17) {
    throw new RuntimeException('Unexpected table inventory.');
}
foreach (["SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='ereserve'", "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='ereserve'", "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='ereserve'", "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='ereserve'"] as $query) {
    if ($source->query($query)->fetchColumn() != 0) {
        throw new RuntimeException('Executable objects/views require a separate reviewed backup procedure.');
    }
}
clientFile($directory.'/source.cnf', $sourceConfig);
try {
    $source->exec('SET SESSION lock_wait_timeout=15');
    $source->exec('SET autocommit=0');
    $source->exec('LOCK TABLES '.implode(', ', array_map(fn ($table) => identifier($table).' READ', $tables)));
    try {
        $baseline = snapshot($source, $tables);
        exclusiveJson($directory.'/baseline.json', $baseline);
        clientRun([$bin.'/mysqldump.exe', '--defaults-file='.realpath($directory.'/source.cnf'), '--skip-lock-tables', '--skip-add-locks', '--no-tablespaces', '--set-gtid-purged=OFF', '--hex-blob', '--skip-triggers', '--default-character-set=utf8mb4', 'ereserve'], 'NUL', $directory.'/backup.sql', $directory.'/dump.stderr');
        if ($baseline !== snapshot($source, $tables)) {
            throw new RuntimeException('Source changed during locked backup.');
        }
    } finally {
        $source->exec('UNLOCK TABLES');
        $source->exec('SET autocommit=1');
    }
} finally {
    unlink($directory.'/source.cnf');
}
$testConfig = [...$sourceConfig, 'database' => 'ereserve_p1_'.$suffix, 'username' => 'ep1_'.substr($suffix, -8), 'password' => bin2hex(random_bytes(24))];
$source->exec('CREATE DATABASE '.identifier($testConfig['database']).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$account = $source->quote($testConfig['username'])."@'localhost'";
$source->exec('CREATE USER '.$account.' IDENTIFIED BY '.$source->quote($testConfig['password']));
$source->exec('GRANT ALL PRIVILEGES ON `'.str_replace('_', '\\_', $testConfig['database']).'`.* TO '.$account);
exclusiveJson($directory.'/connection.json', $testConfig);
$test = connect($testConfig);
$grants = assertIsolated($test, $testConfig);
clientFile($directory.'/test.cnf', $testConfig);
try {
    clientRun([$bin.'/mysql.exe', '--defaults-file='.realpath($directory.'/test.cnf'), '--binary-mode', '--default-character-set=utf8mb4', $testConfig['database']], $directory.'/backup.sql', $directory.'/restore.stdout', $directory.'/restore.stderr');
} finally {
    unlink($directory.'/test.cnf');
}
$restored = snapshot($test, $tables);
if ($baseline !== $restored) {
    exclusiveJson($directory.'/restore-mismatch.json', $restored);
    throw new RuntimeException('Restore is not identical. No repair testing is permitted.');
}
$verification = ['database' => $testConfig['database'], 'backup_sha256' => hash_file('sha256', $directory.'/backup.sql'), 'backup_bytes' => filesize($directory.'/backup.sql'), 'tables_verified' => count($tables), 'row_counts' => array_map(fn ($t) => $t['count'], $baseline), 'restore_identical' => true, 'working_database_access_denied' => true, 'grants' => $grants, 'queue_workers_started' => false];
exclusiveJson($directory.'/verified.json', $verification);
echo json_encode(['artifacts' => realpath($directory), ...$verification], JSON_PRETTY_PRINT).PHP_EOL;
