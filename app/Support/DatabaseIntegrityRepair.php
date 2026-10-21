<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Forward-only, metadata-checked repairs. Never rewrites application records. */
final class DatabaseIntegrityRepair
{
    public static function innodb(string $table): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $engine = self::engine($table);
        if ($engine === 'INNODB') {
            return;
        }
        if ($engine !== 'MYISAM') {
            throw new RuntimeException("Unexpected or missing engine for {$table}; repair stopped.");
        }
        DB::statement('ALTER TABLE '.self::quote($table).' ENGINE=InnoDB');
        if (self::engine($table) !== 'INNODB') {
            throw new RuntimeException("InnoDB conversion failed for {$table}.");
        }
    }

    public static function foreignKey(string $table, string $column, string $parent, string $delete, bool $replaceLegacyNullRule = false): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if (! in_array($delete, ['RESTRICT', 'CASCADE'], true) || self::engine($table) !== 'INNODB' || self::engine($parent) !== 'INNODB') {
            throw new RuntimeException('Foreign key repair requires InnoDB and an approved delete rule.');
        }
        $name = $table.'_'.$column.'_foreign';
        $drop = '';
        $matching = array_values(array_filter(Schema::getForeignKeys($table), fn ($key) => in_array($column, $key['columns'], true) || $key['name'] === $name));
        if (count($matching) > 1) {
            throw new RuntimeException("Ambiguous foreign keys for {$table}.{$column}.");
        }
        if ($matching) {
            $key = $matching[0];
            $targetMatches = $key['columns'] === [$column] && $key['foreign_table'] === $parent
                && $key['foreign_columns'] === ['id'] && $key['foreign_schema'] === DB::getDatabaseName()
                && in_array(strtoupper($key['on_update']), ['RESTRICT', 'NO ACTION'], true);
            if ($targetMatches && (strtoupper($key['on_delete']) === $delete || ($delete === 'RESTRICT' && strtoupper($key['on_delete']) === 'NO ACTION'))) {
                return;
            }
            // Fresh databases may have the old applied migration's SET NULL rule.
            // Strengthen only this precisely identified resource relationship atomically.
            if ($targetMatches && $replaceLegacyNullRule && $table === 'reservations' && $column === 'facility_id'
                && $parent === 'facilities' && $delete === 'RESTRICT' && strtoupper($key['on_delete']) === 'SET NULL') {
                // MySQL rejects dropping/re-adding the same symbol in one ALTER.
                $name = $key['name'] === $name ? $table.'_'.$column.'_retained_foreign' : $name;
                $drop = 'DROP FOREIGN KEY '.self::quote($key['name']).', ';
            } else {
                throw new RuntimeException("Conflicting foreign key for {$table}.{$column}; no constraint was removed.");
            }
        }
        if (DB::table($table.' as child')->leftJoin($parent.' as parent', 'child.'.$column, '=', 'parent.id')
            ->whereNotNull('child.'.$column)->whereNull('parent.id')->exists()) {
            throw new RuntimeException("Orphaned {$table}.{$column}; preserve records and resolve separately before retrying.");
        }
        DB::statement('ALTER TABLE '.self::quote($table).' '.$drop.'ADD CONSTRAINT '.self::quote($name)
            .' FOREIGN KEY ('.self::quote($column).') REFERENCES '.self::quote($parent).' (`id`) ON DELETE '.$delete.' ON UPDATE RESTRICT');
    }

    private static function engine(string $table): string
    {
        return strtoupper((string) DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)->value('ENGINE'));
    }

    private static function quote(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/D', $identifier)) {
            throw new RuntimeException('Unsafe schema identifier.');
        }

        return '`'.$identifier.'`';
    }
}
