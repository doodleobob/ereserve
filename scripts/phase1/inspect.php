<?php

require __DIR__.'/../../vendor/autoload.php';
$env = Dotenv\Dotenv::createArrayBacked(__DIR__.'/../../')->load();
if (! empty($env['DB_URL']) || ($env['DB_DATABASE'] ?? '') !== 'ereserve') {
    throw new RuntimeException('Unexpected source configuration; inspect manually.');
}
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? 3306).';dbname=ereserve;charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$queries = [
    'server' => 'SELECT VERSION() AS version, CURRENT_USER() AS account, @@default_storage_engine AS default_engine',
    'tables' => "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='ereserve' ORDER BY TABLE_NAME",
    'foreign_keys' => "SELECT k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA='ereserve' ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION",
    'columns' => "SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='ereserve' AND (COLUMN_NAME='id' OR COLUMN_NAME LIKE '%\\_id') ORDER BY TABLE_NAME,ORDINAL_POSITION",
    'objects' => "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='ereserve'",
    'orphans' => "SELECT 'reservations.user_id' AS relationship, COUNT(*) AS orphan_count FROM reservations c LEFT JOIN users p ON p.id=c.user_id WHERE c.user_id IS NOT NULL AND p.id IS NULL
        UNION ALL SELECT 'reservations.facility_id', COUNT(*) FROM reservations c LEFT JOIN facilities p ON p.id=c.facility_id WHERE c.facility_id IS NOT NULL AND p.id IS NULL
        UNION ALL SELECT 'facility_photos.facility_id', COUNT(*) FROM facility_photos c LEFT JOIN facilities p ON p.id=c.facility_id WHERE c.facility_id IS NOT NULL AND p.id IS NULL
        UNION ALL SELECT 'two_factor_challenges.user_id', COUNT(*) FROM two_factor_challenges c LEFT JOIN users p ON p.id=c.user_id WHERE c.user_id IS NOT NULL AND p.id IS NULL",
];
foreach ($queries as $label => $query) {
    echo $label.': '.json_encode($pdo->query($query)->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT).PHP_EOL;
}
