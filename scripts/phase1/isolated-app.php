<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/common.php';

function isolatedApplication(): Application
{
    $directory = realpath(getenv('PHASE1_ARTIFACT_DIR') ?: '');
    $root = realpath(__DIR__.'/../../storage/app/phase1');
    if (! $directory || ! $root || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR) || ! is_file($directory.'/verified.json')) {
        throw new RuntimeException('A verified Phase 1 restore is required.');
    }
    $config = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);
    $verified = json_decode(file_get_contents($directory.'/verified.json'), true, flags: JSON_THROW_ON_ERROR);
    if (hash_file('sha256', $directory.'/backup.sql') !== $verified['backup_sha256'] || $config['database'] !== $verified['database']) {
        throw new RuntimeException('Verified backup identity changed.');
    }
    assertIsolated(connect($config), $config);
    foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php', 'APP_ROUTES_CACHE' => $directory.'/unused-routes.php', 'APP_EVENTS_CACHE' => $directory.'/unused-events.php'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->useEnvironmentPath($directory)->loadEnvironmentFrom('.env.disabled');
    $app->beforeBootstrapping(RegisterProviders::class, function ($app) use ($config, $directory) {
        $app['config']->set([
            'app.env' => 'testing', 'app.debug' => true, 'app.url' => 'http://phase1.test',
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'database.default' => 'mysql',
            'database.connections' => ['mysql' => [...$config, 'driver' => 'mysql', 'url' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => $app['config']->get('database.connections.mysql.engine')]],
            'database.redis' => [], 'cache.default' => 'array', 'cache.stores' => ['array' => ['driver' => 'array']],
            'session.driver' => 'array', 'session.secure' => false,
            'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']],
            'queue.default' => 'null', 'queue.connections' => ['null' => ['driver' => 'null'], 'phase1' => ['driver' => 'database', 'connection' => 'mysql', 'table' => 'jobs', 'queue' => 'phase1_synthetic', 'retry_after' => 90, 'after_commit' => false]],
            'queue.failed.driver' => 'null', 'queue.batching.database' => 'mysql',
            'broadcasting.default' => 'null', 'broadcasting.connections' => ['null' => ['driver' => 'null']],
            'filesystems.default' => 'local', 'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => $directory.'/files'], 'public' => ['driver' => 'local', 'root' => $directory.'/public']],
            'logging.default' => 'single', 'logging.channels.single.path' => $directory.'/laravel.log',
            'view.compiled' => $directory.'/views', 'hashing.bcrypt.rounds' => 4,
        ]);
        if (! is_dir($directory.'/views')) {
            mkdir($directory.'/views');
        }
    });
    $app->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    Notification::fake();
    assertIsolated(DB::connection()->getPdo(), $config);

    return $app;
}
