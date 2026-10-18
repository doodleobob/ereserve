<?php

// Explicit MySQL concurrency check. Only randomly prefixed scratch tables are mutated.
// Run: php tests/Integration/equipment-acceptance-mysql.php
use App\Http\Controllers\ReservationController;
use App\Models\Facility;
use App\Models\Reservation;
use App\Models\User;
use App\Policies\ReservationPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('This check requires MySQL or MariaDB.');
}
$worker = ($argv[1] ?? '') === 'worker';
$prefix = $worker ? ($argv[2] ?? '') : 'equipment_probe_'.bin2hex(random_bytes(6)).'_';
if (! preg_match('/^equipment_probe_[a-f0-9]{12}_$/D', $prefix)) {
    throw new RuntimeException('Invalid scratch table prefix.');
}
config(['database.connections.equipment_probe' => [...$connection->getConfig(), 'name' => 'equipment_probe', 'prefix' => $prefix]]);
DB::setDefaultConnection('equipment_probe');
Schema::swap(DB::connection()->getSchemaBuilder());
Notification::fake();

if ($worker) {
    $admin = User::findOrFail(1);
    Auth::setUser($admin);
    Gate::swap(Gate::forUser($admin));
    $reservation = Reservation::findOrFail((int) $argv[3]);
    if ($reservation->getConnectionName() !== 'equipment_probe' || $reservation->facility?->getConnectionName() !== 'equipment_probe') {
        throw new RuntimeException('Probe model relationships must use the isolated connection.');
    }
    Gate::policy(Reservation::class, ReservationPolicy::class);
    if (! (new ReservationPolicy)->manage($admin, $reservation)) {
        fwrite(STDERR, 'Probe policy denied: '.json_encode([$admin->role, $admin->barangay, $reservation->managingBarangay()])."\n");
        exit(1);
    }
    $request = Request::create('/accept', 'POST', ['total_payment' => '200.00', 'payment_confirmed' => 1]);
    $request->headers->set('Accept', 'application/json');
    $request->setUserResolver(fn () => $admin);
    echo "READY\n";
    flush();
    try {
        app(ReservationController::class)->accept($request, $reservation);
        echo "ACCEPTED\n";
    } catch (ValidationException $exception) {
        if (! isset($exception->errors()['quantity']) && ! isset($exception->errors()['reservation'])) {
            throw $exception;
        }
        echo 'BLOCKED '.json_encode($exception->errors())."\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
    exit;
}

$tables = [];
$processes = [];
$checks = 0;
$failure = null;
$check = function (bool $condition, string $message) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$create = function (string $name, Closure $columns) use (&$tables) {
    Schema::create($name, function (Blueprint $table) use ($columns) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->timestamps();
        $columns($table);
    });
    $tables[] = $name;
};
try {
    $create('users', function (Blueprint $table) {
        foreach (['name', 'email', 'role', 'barangay'] as $column) {
            $table->string($column);
        }
    });
    $create('facilities', function (Blueprint $table) {
        foreach (['barangay', 'slug', 'name', 'category', 'description', 'location', 'status'] as $column) {
            $table->string($column);
        }
        $table->unsignedInteger('capacity');
        $table->decimal('hourly_rate', 10, 2)->default(100);
    });
    $create('reservations', function (Blueprint $table) {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('facility_id')->nullable();
        foreach (['barangay', 'facility_slug', 'facility_name', 'category', 'location', 'purpose', 'status'] as $column) {
            $table->string($column);
        }
        $table->date('reservation_date');
        $table->time('start_time');
        $table->time('end_time');
        $table->unsignedInteger('attendees');
        $table->decimal('hourly_rate_snapshot', 10, 2)->nullable();
        $table->decimal('total_payment', 12, 2)->nullable();
    });
    $create('official_uses', function (Blueprint $table) {
        $table->unsignedBigInteger('facility_id');
        $table->string('barangay');
        $table->string('status');
        $table->date('date');
        $table->time('start_time');
        $table->time('end_time');
    });
    $create('payments', function (Blueprint $table) {
        $table->unsignedBigInteger('reservation_id')->unique();
        $table->decimal('amount', 12, 2);
        $table->string('payment_status');
    });
    DB::table('users')->insert(['id' => 1, 'name' => 'Probe Admin', 'email' => 'probe@example.invalid', 'role' => 'admin', 'barangay' => 'Taft']);
    DB::table('facilities')->insert(['id' => 1, 'barangay' => 'Taft', 'slug' => 'probe', 'name' => 'Probe', 'category' => 'Equipment', 'description' => 'Probe', 'location' => 'Taft', 'status' => 'Available', 'capacity' => 200]);
    $legacy = ['id' => 1, 'user_id' => 1, 'facility_id' => 1, 'barangay' => 'Taft', 'facility_slug' => 'probe', 'facility_name' => 'Probe', 'category' => 'Equipment', 'location' => 'Taft', 'purpose' => 'Probe', 'status' => 'accepted', 'reservation_date' => '2030-10-20', 'start_time' => '08:00', 'end_time' => '10:00', 'attendees' => 23, 'hourly_rate_snapshot' => '100.00', 'total_payment' => '375.00'];
    DB::table('reservations')->insert($legacy);
    $before = (array) DB::table('reservations')->first();
    $migration = require __DIR__.'/../../database/migrations/2026_10_18_000001_add_equipment_quantities.php';
    $migration->up();
    $after = (array) DB::table('reservations')->first();
    unset($after['quantity']);
    $check($before === $after, 'Migration changed a historical booking.');
    $check(DB::table('reservations')->value('quantity') === null, 'Migration inferred a legacy quantity.');
    $check(DB::table('facilities')->value('total_quantity') === null, 'Migration inferred stock.');
    $migration->down();
    $check(! Schema::hasColumn('reservations', 'quantity'), 'Unused migration did not roll back.');
    $migration->up();
    DB::table('facilities')->where('id', 1)->update(['total_quantity' => 100]);
    DB::table('reservations')->where('id', 1)->update(['status' => 'pending', 'quantity' => 70, 'total_payment' => null]);
    DB::table('reservations')->insert([...$legacy, 'id' => 2, 'status' => 'pending', 'quantity' => 70, 'total_payment' => null]);
    try {
        $migration->down();
        throw new LogicException('Recorded quantities were erased on rollback.');
    } catch (RuntimeException $exception) {
        $check(str_contains($exception->getMessage(), 'Cannot remove equipment quantities'), 'Unexpected rollback exception.');
    }
    // Hold the exact parent row lock used by acceptance so both workers contend simultaneously.
    DB::beginTransaction();
    Facility::lockForUpdate()->findOrFail(1);
    foreach ([1, 2] as $id) {
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', $prefix, (string) $id], dirname(__DIR__, 2));
        $process->setTimeout(60);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 45;
    while (collect($processes)->contains(fn ($process) => ! str_contains($process->getOutput(), 'READY'))) {
        if (microtime(true) > $deadline || collect($processes)->contains(fn ($process) => ! $process->isRunning())) {
            throw new RuntimeException('Workers did not reach the acceptance barrier: '.implode(' ', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
        }
        usleep(20000);
    }
    $check(collect($processes)->every(fn ($process) => ! str_contains($process->getOutput(), 'ACCEPTED')), 'Worker bypassed the resource lock.');
    DB::commit();
    foreach ($processes as $process) {
        $process->wait();
        $check($process->isSuccessful(), 'Acceptance worker failed: '.$process->getErrorOutput().$process->getOutput());
    }
    $check(Reservation::where('status', 'accepted')->count() === 1, 'Expected one accepted booking; got '.Reservation::where('status', 'accepted')->count().'. Workers: '.implode(' ', array_map(fn ($p) => $p->getOutput(), $processes)));
    $check(Reservation::where('status', 'pending')->count() === 1, 'Losing request was not kept pending.');
    $check(DB::table('payments')->count() === 1, 'Failed acceptance recorded a payment.');
    echo "MySQL equipment migration and concurrency checks passed: {$checks}. Application records untouched.\n";
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    if (DB::transactionLevel()) {
        DB::rollBack();
    }
    // Only tables created by this invocation, with the validated random prefix, are removed.
    foreach (array_reverse($tables) as $table) {
        Schema::drop($table);
    }
}
if ($failure) {
    fwrite(STDERR, $failure->getMessage()."\n");
    exit(1);
}
