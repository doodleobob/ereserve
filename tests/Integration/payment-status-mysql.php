<?php

// Run explicitly with: php tests/Integration/payment-status-mysql.php
// Uses uniquely prefixed scratch tables in the configured MySQL database.
// Never reads or writes application payment or reservation records.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$original = DB::getDefaultConnection();
$connection = DB::connection();
if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('This explicit integration check requires MySQL or MariaDB.');
}
$prefix = 'refund_enum_probe_'.bin2hex(random_bytes(8)).'_';
config(['database.connections.refund_enum_probe' => [...$connection->getConfig(), 'prefix' => $prefix]]);
DB::setDefaultConnection('refund_enum_probe');
Schema::swap(DB::connection()->getSchemaBuilder());
$created = false;
$failure = null;
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$migration = require __DIR__.'/../../database/migrations/2026_10_14_000001_add_refund_pending_to_payments.php';
$column = fn () => collect(Schema::getColumns('payments'))->firstWhere('name', 'payment_status');

try {
    Schema::create('payments', function (Blueprint $table) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->enum('payment_status', ['pending', 'paid', 'refunded', 'cancelled', 'legacy_status'])->default('pending');
        $table->decimal('amount', 12, 2);
        $table->json('history')->nullable();
        $table->unsignedInteger('revision')->default(0);
    });
    $created = true;
    DB::table('payments')->insert(['payment_status' => 'paid', 'amount' => '123.45', 'history' => '[{"before":"pending","after":"paid"}]', 'revision' => 1]);
    $snapshot = DB::table('payments')->get()->toJson();

    foreach ([[false, 'pending'], [true, 'paid'], [true, null]] as [$nullable, $default]) {
        Schema::table('payments', function (Blueprint $table) use ($nullable, $default) {
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'cancelled', 'legacy_status'])
                ->nullable($nullable)->default($default)->comment('Retain payment metadata')->change();
        });
        $before = $column();
        $migration->up();
        $migration->up();
        $after = $column();
        $check($after['type'] === "enum('pending','paid','refunded','cancelled','legacy_status','refund_pending')", 'Enum values or order changed.');
        foreach (['nullable', 'default', 'collation', 'comment'] as $attribute) {
            $check($before[$attribute] === $after[$attribute], 'Column attribute changed: '.$attribute);
        }
        $check($snapshot === DB::table('payments')->get()->toJson(), 'Financial record changed.');
        // Exercise the actual MySQL assignment that previously raised error 1265.
        DB::beginTransaction();
        DB::table('payments')->where('id', 1)->update(['payment_status' => 'refund_pending']);
        $check(DB::table('payments')->value('payment_status') === 'refund_pending', 'Refund Pending assignment failed.');
        DB::rollBack();
        $migration->down();
        $check($before === $column(), 'Rollback did not restore the original column definition.');
        $check($snapshot === DB::table('payments')->get()->toJson(), 'Rollback changed payment data.');
    }

    $migration->up();
    DB::table('payments')->where('id', 1)->update(['payment_status' => 'refund_pending']);
    try {
        $migration->down();
        throw new LogicException('Rollback discarded an outstanding refund.');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'Cannot remove refund_pending'), 'Unexpected rollback failure.');
    }
    $check(DB::table('payments')->value('payment_status') === 'refund_pending', 'Blocked rollback changed a refund.');
    DB::table('payments')->where('id', 1)->update(['payment_status' => 'refunded']);
    $migration->down();
    $check(DB::table('payments')->value('payment_status') === 'refunded', 'Rollback changed a confirmed refund.');
    echo "MySQL enum integration checks passed: {$checks}. Application records untouched.\n";
} catch (Throwable $e) {
    $failure = $e;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if ($created && DB::connection()->getTablePrefix() === $prefix) {
        Schema::drop('payments'); // Only the scratch table created by this process.
    }
    DB::setDefaultConnection($original);
    Schema::swap($connection->getSchemaBuilder());
    DB::purge('refund_enum_probe');
}
if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
