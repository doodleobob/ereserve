<?php

// Creates a NEW disposable database and a database-scoped account. Never migrates the working DB.
// php -d xdebug.mode=off tests/Integration/resource-usage-mysql.php
use App\Http\Controllers\ReservationController;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\ResourceUsage;
use App\Models\ResourceUsageEvent;
use App\Models\User;
use App\Services\ResourceUsageManagement;
use App\Support\ResourceUsageQuery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$worker = ($argv[1] ?? '') === 'worker';
$root = realpath(__DIR__.'/../../storage/app');
if ($worker) {
    $directory = realpath($argv[2] ?? '');
    if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'usage_mysql_')) {
        throw new RuntimeException('Invalid isolated artifact directory.');
    }
    $config = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);
} else {
    $env = Dotenv\Dotenv::createArrayBacked(__DIR__.'/../../')->load();
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? 3306;
    // Connect without selecting any application database. Credentials are never printed.
    $adminConnection = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $suffix = gmdate('YmdHis').'_'.bin2hex(random_bytes(4));
    $database = 'ereserve_usage_test_'.$suffix;
    $username = 'usage_'.substr($suffix, -8);
    $password = bin2hex(random_bytes(24));
    $adminConnection->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $account = $adminConnection->quote($username)."@'localhost'";
    $adminConnection->exec('CREATE USER '.$account.' IDENTIFIED BY '.$adminConnection->quote($password));
    $adminConnection->exec('GRANT ALL PRIVILEGES ON `'.str_replace('_', '\\_', $database).'`.* TO '.$account);
    $config = compact('host', 'port', 'database', 'username', 'password');
    $directory = $root.'/usage_mysql_'.$suffix;
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/connection.json', json_encode($config, JSON_THROW_ON_ERROR));
    $adminConnection = null;
}
if (! preg_match('/^ereserve_usage_test_[0-9]{14}_[a-f0-9]{8}$/D', $config['database'])) {
    throw new RuntimeException('Refusing to use a non-disposable database.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php', 'APP_ROUTES_CACHE' => $directory.'/unused-routes.php', 'APP_EVENTS_CACHE' => $directory.'/unused-events.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useEnvironmentPath($directory)->loadEnvironmentFrom('.env.disabled');
$app->beforeBootstrapping(RegisterProviders::class, function ($app) use ($config, $directory) {
    $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('u', 32)),
        'database.default' => 'mysql', 'database.connections' => ['mysql' => [...$config, 'driver' => 'mysql', 'url' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB']],
        'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'queue.default' => 'null',
        'hashing.bcrypt.rounds' => 4, 'logging.default' => 'single', 'logging.channels.single.path' => $directory.'/laravel.log']);
});
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT DATABASE() AS name')->name !== $config['database']) {
    throw new RuntimeException('Connection identity mismatch.');
}
Notification::fake();

if ($worker) {
    $reservation = Reservation::findOrFail((int) $argv[3]);
    echo "READY\n";
    flush();
    while (! is_file($directory.'/go-'.$argv[7])) {
        usleep(10000);
    }
    try {
        $actor = User::findOrFail(1);
        if ($argv[4] === 'accept') {
            Auth::setUser($actor);
            $request = Request::create('/reservations/'.$reservation->id.'/accept', 'POST', ['total_payment' => 200, 'payment_confirmed' => 1]);
            $request->headers->set('Accept', 'application/json');
            $request->setUserResolver(fn () => $actor);
            app(ReservationController::class)->accept($request, $reservation);
        } else {
            app(ResourceUsageManagement::class)->record($actor, $reservation, ['action' => $argv[4], 'quantity' => (int) $argv[5], 'request_key' => $argv[6], 'confirmed' => 1]);
        }
        echo "SAVED\n";
    } catch (ValidationException) {
        echo "BLOCKED\n";
    }
    exit;
}

// Confirm that this account has no privilege to read the working database.
$denied = false;
try {
    DB::select('SELECT 1 FROM ereserve.users LIMIT 0');
} catch (QueryException) {
    $denied = true;
}
if (! $denied) {
    throw new RuntimeException('Test account can access the working database; refusing migration.');
}
Artisan::call('migrate', ['--force' => true]);
$admin = User::factory()->create(['role' => 'admin', 'barangay' => 'Taft']);
$resident = User::factory()->create(['barangay' => 'Taft']);
$facility = Facility::create(['barangay' => 'Taft', 'slug' => 'chairs', 'name' => 'Chairs', 'category' => 'Equipment', 'description' => 'Test chairs', 'total_quantity' => 100, 'location' => 'Taft', 'status' => 'Available']);
$book = fn ($days, $quantity = 70) => Reservation::create(['user_id' => $resident->id, 'facility_id' => $facility->id, 'barangay' => 'Taft', 'facility_slug' => $facility->slug, 'facility_name' => $facility->name, 'category' => 'Equipment', 'location' => 'Taft', 'reservation_date' => today()->addDays($days)->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00', 'status' => 'accepted', 'purpose' => 'Isolated concurrency test', 'quantity' => $quantity, 'attendees' => null]);
$first = $book(1);
$second = $book(2);
$checks = [];
$check = function ($condition, $label) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$race = function (array $requests) use ($directory): array {
    $batch = bin2hex(random_bytes(4));
    $processes = [];
    try {
        foreach ($requests as [$reservation, $action, $quantity, $key]) {
            $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', $directory, (string) $reservation->id, $action, (string) $quantity, $key, $batch], timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        foreach ($processes as $process) {
            while (! str_contains($process->getOutput(), 'READY')) {
                if (! $process->isRunning() || microtime(true) > $deadline) {
                    throw new RuntimeException('Concurrency worker failed to become ready: '.$process->getErrorOutput());
                }
                usleep(10000);
            }
        }
        file_put_contents($directory.'/go-'.$batch, 'go');
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Concurrency worker failed: '.$process->getErrorOutput().$process->getOutput());
            }
        }

        return array_map(fn ($process) => str_contains($process->getOutput(), 'SAVED') ? 'saved' : 'blocked', $processes);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
};
$result = $race([[$first, 'release', 70, (string) Str::uuid()], [$second, 'release', 70, (string) Str::uuid()]]);
sort($result);
$check($result === ['blocked', 'saved'], 'Concurrent releases across reservations cannot exceed physical inventory');
$loan = ResourceUsage::firstOrFail();
$borrower = $loan->reservation;
$other = $borrower->id === $first->id ? $second : $first;
$key = (string) Str::uuid();
$result = $race([[$borrower, 'return', 20, $key], [$borrower, 'return', 20, $key]]);
$check($result === ['saved', 'saved'] && $loan->fresh()->returned_quantity === 20, 'Concurrent identical return requests apply exactly once');
$check(ResourceUsageEvent::count() === 2, 'Duplicate requests do not create duplicate audit events');
$result = $race([[$borrower, 'return', 40, (string) Str::uuid()], [$borrower, 'return', 40, (string) Str::uuid()]]);
sort($result);
$check($result === ['blocked', 'saved'] && $loan->fresh()->outstanding() === 10, 'Distinct concurrent over-returns are rejected');
$result = $race([[$borrower, 'return', 10, (string) Str::uuid()], [$other, 'release', 70, (string) Str::uuid()]]);
$check($result === ['saved', 'saved'] && ResourceUsageManagement::inventory($facility)['available'] === 30, 'Concurrent return and different-reservation release remain consistent');
foreach (['resource_usages', 'resource_usage_events'] as $table) {
    $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->engine;
    $check($engine === 'InnoDB', $table.' uses InnoDB');
}
foreach ([fn () => DB::table('resource_usage_events')->update(['quantity' => 1]), fn () => DB::table('resource_usages')->update(['returned_quantity' => 999])] as $index => $invalid) {
    $blocked = false;
    try {
        $invalid();
    } catch (QueryException) {
        $blocked = true;
    }
    $check($blocked, 'MySQL rejects invalid write '.($index + 1));
}
$request = Request::create('/resource-usage', 'GET', ['sort' => 'overdue', 'overdue' => 1]);
$request->setUserResolver(fn () => $admin);
ResourceUsageQuery::forRequest($request)->get();
$check(true, 'MySQL overdue and overnight query executes');
$pendingA = $book(10);
$pendingB = $book(10);
$pendingA->update(['status' => 'pending']);
$pendingB->update(['status' => 'pending']);
$result = $race([[$pendingA, 'accept', 0, (string) Str::uuid()], [$pendingB, 'accept', 0, (string) Str::uuid()]]);
sort($result);
$check($result === ['blocked', 'saved'], 'Concurrent acceptance reviews cannot allocate the same scheduled stock twice');
$check(Reservation::whereIn('id', [$pendingA->id, $pendingB->id])->where('status', 'accepted')->count() === 1 && DB::table('payments')->count() === 1, 'Only successful acceptance records payment');
$check(ResourceUsageManagement::inventory($facility)['available'] === 30, 'Acceptance does not alter physical usage balances');
$result = ['database' => $config['database'], 'working_database_access_denied' => true, 'checks' => $checks, 'retained_for_review' => true];
file_put_contents($directory.'/results.json', json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
