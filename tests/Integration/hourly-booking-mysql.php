<?php

// Run explicitly: php tests/Integration/hourly-booking-mysql.php
// Reuse existing concurrency/refund checks in a newly created, disposable database.
// The configured account needs CREATE/DROP DATABASE permission. Application tables are never used.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('The explicit concurrency check requires a MySQL or MariaDB server.');
}
$database = 'ereserve_hourly_test_'.bin2hex(random_bytes(8));
$created = false;
try {
    $connection->statement('CREATE DATABASE `'.$database.'`');
    $created = true;
    $environment = [
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/app/hourly-test-no-config.php',
        'DB_CONNECTION' => $connection->getDriverName(), 'DB_DATABASE' => $database, 'DB_URL' => '',
        'ERESERVE_MYSQL_TEST_DATABASE' => $database,
        'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
    ];
    foreach (['equipment-acceptance-mysql.php', 'payment-status-mysql.php'] as $script) {
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__.'/'.$script], dirname(__DIR__, 2), $environment);
        $process->setTimeout(120);
        $process->mustRun();
        echo $process->getOutput();
    }
} finally {
    if ($created && preg_match('/^ereserve_hourly_test_[a-f0-9]{16}$/D', $database)) {
        // Drop only the unique database successfully created by this invocation.
        $connection->statement('DROP DATABASE `'.$database.'`');
        echo "Disposable integration database removed.\n";
    }
}
