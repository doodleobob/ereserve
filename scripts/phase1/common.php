<?php

require_once __DIR__.'/../../vendor/autoload.php';

function connect(array $config): PDO
{
    return new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]);
}

function failOnException(): void
{
    // Laravel's console exception renderer is not the entry point for these scripts.
    set_exception_handler(function (Throwable $exception): never {
        fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
        exit(1);
    });
}

function identifier(string $name): string
{
    if (! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
        throw new RuntimeException('Unexpected identifier.');
    }

    return '`'.$name.'`';
}

function exclusiveJson(string $path, array $data): void
{
    $handle = fopen($path, 'x');
    if (! $handle) {
        throw new RuntimeException('Refusing to overwrite artifact.');
    }
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fclose($handle);
}

function snapshot(PDO $pdo, array $tables): array
{
    $result = [];
    foreach ($tables as $table) {
        $quoted = identifier($table);
        $columns = $pdo->query('SHOW FULL COLUMNS FROM '.$quoted)->fetchAll(PDO::FETCH_ASSOC);
        $indexes = $pdo->query('SHOW INDEX FROM '.$quoted)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as &$index) {
            unset($index['Cardinality']);
        }
        unset($index);
        $primary = array_column(array_filter($indexes, fn ($i) => $i['Key_name'] === 'PRIMARY'), 'Column_name');
        if (! $primary) {
            throw new RuntimeException('A deterministic primary key is required: '.$table);
        }
        $hash = hash_init('sha256');
        $count = 0;
        foreach ($pdo->query('SELECT * FROM '.$quoted.' ORDER BY '.implode(',', array_map('identifier', $primary))) as $row) {
            // PDO defaults to FETCH_BOTH; hash named fields only, preserving NULL and exact bytes.
            $row = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
            hash_update($hash, serialize($row));
            $count++;
        }
        $result[$table] = ['count' => $count, 'sha256' => hash_final($hash), 'columns' => $columns, 'indexes' => $indexes,
            // mysqldump sets explicit column charsets; the same collation implies that charset.
            'create' => str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_', ' COLLATE utf8mb4_', $pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1])];
    }

    return $result;
}

function clientFile(string $path, array $config): void
{
    $text = "[client]\n";
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $value = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $config[$key]);
        $text .= ($key === 'username' ? 'user' : $key).'="'.$value."\"\n";
    }
    $handle = fopen($path, 'x');
    if (! $handle) {
        throw new RuntimeException('Client file already exists.');
    }
    fwrite($handle, $text);
    fclose($handle);
}

function clientRun(array $command, string $input, string $output, string $error): void
{
    $process = proc_open($command, [0 => ['file', $input, 'r'], 1 => ['file', $output, 'x'], 2 => ['file', $error, 'x']], $pipes, null, null, ['bypass_shell' => true]);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('MySQL client failed; see private error artifact.');
    }
}

function assertIsolated(PDO $pdo, array $config): array
{
    if (! preg_match('/^ereserve_p1_[0-9]{14}_[a-f0-9]{8}$/D', $config['database']) || $config['database'] !== $pdo->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Not an isolated Phase 1 database.');
    }
    $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($grants as $grant) {
        if (str_starts_with($grant, 'GRANT USAGE ON *.*')) {
            continue;
        }
        // MySQL escapes wildcard characters in database-level grants.
        if (! str_contains(str_replace('\\', '', $grant), ' ON `'.$config['database'].'`.* TO ') || str_contains($grant, 'WITH GRANT OPTION')) {
            throw new RuntimeException('Test account has unexpected privileges.');
        }
    }
    try {
        $pdo->query('SELECT 1 FROM `ereserve`.`users` LIMIT 0');
        throw new RuntimeException('Test account can access working data.');
    } catch (PDOException $exception) {
        if (! in_array($exception->errorInfo[1], [1044, 1142], true)) {
            throw $exception;
        }
    }

    return $grants;
}
