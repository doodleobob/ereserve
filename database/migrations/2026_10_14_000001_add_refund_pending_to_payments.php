<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changeEnum(add: true);
    }

    public function down(): void
    {
        if (DB::table('payments')->where('payment_status', 'refund_pending')->exists()) {
            throw new RuntimeException('Cannot remove refund_pending while payments use it. Resolve refunds through the confirmed refund workflow before rollback; no payment data was changed.');
        }

        $this->changeEnum(add: false);
    }

    private function changeEnum(bool $add): void
    {
        $column = collect(Schema::getColumns('payments'))->firstWhere('name', 'payment_status');
        if ($column === null) {
            throw new RuntimeException('payments.payment_status is missing. No payment data was changed.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            if ($column['type_name'] !== 'enum') {
                throw new RuntimeException('Expected a payment_status enum. No payment data was changed.');
            }
            // Retain the exact SQL literals, including escaping and enum order.
            preg_match_all("/'(?:[^'\\\\]|\\\\.|'')*'/", $column['type'], $matches);
            $values = $matches[0];
            if (implode(',', $values) !== substr($column['type'], 5, -1)) {
                throw new RuntimeException('Could not parse payment_status enum values. No payment data was changed.');
            }
            if ($add === in_array("'refund_pending'", $values, true)) {
                return;
            }
            if (! $add && $column['default'] === 'refund_pending') {
                throw new RuntimeException('Cannot remove refund_pending while it is the column default. Review the default before rollback.');
            }
            $values = $add ? [...$values, "'refund_pending'"] : array_values(array_diff($values, ["'refund_pending'"]));
            $type = 'enum('.implode(',', $values).')';
            $table = DB::connection()->getQueryGrammar()->wrapTable('payments');
            $create = (array) DB::selectOne('SHOW CREATE TABLE '.$table);
            // Reuse the full definition so NULL, DEFAULT, COLLATE and COMMENT
            // stay exactly as installed, including legacy schema differences.
            if (! preg_match('/^\s*`payment_status` (.+)$/m', $create['Create Table'], $definition)
                || ! str_starts_with($definition[1], $column['type'])) {
                throw new RuntimeException('Could not inspect payment_status definition. No payment data was changed.');
            }
            $attributes = rtrim(trim(substr($definition[1], strlen($column['type']))), ',');
            DB::statement('ALTER TABLE '.$table.' MODIFY COLUMN `payment_status` '.$type.' '.$attributes);

            return;
        }

        // SQLite uses a CHECK constraint in the isolated application tests.
        $values = ['pending', 'paid', 'refunded', 'cancelled'];
        if ($add) {
            $values[] = 'refund_pending';
        }
        $default = $column['default'] === null ? null : str_getcsv($column['default'], ',', "'", '\\')[0];
        if (! $add && $default === 'refund_pending') {
            throw new RuntimeException('Cannot remove refund_pending while it is the column default. Review the default before rollback.');
        }
        Schema::table('payments', function (Blueprint $table) use ($values, $column, $default) {
            $table->enum('payment_status', $values)->nullable($column['nullable'])->default($default)->change();
        });
    }
};
