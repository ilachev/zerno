#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

const BASELINE_SCHEMA_MARKER = 'zerno baseline fixture v1';

function baselineConnect(): PDO
{
    $database = getenv('TEST_DB_NAME');
    if ($database === false || trim($database) === '') {
        throw new RuntimeException('Set TEST_DB_NAME explicitly; application DB_* settings are not used.');
    }
    $portValue = getenv('TEST_DB_PORT');
    $port = filter_var($portValue !== false && $portValue !== '' ? $portValue : '5432', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if (!is_int($port)) {
        throw new RuntimeException('TEST_DB_PORT must be an integer between 1 and 65535.');
    }
    $host = getenv('TEST_DB_HOST') ?: 'localhost';
    $username = getenv('TEST_DB_USER') ?: 'app';
    $password = getenv('TEST_DB_PASSWORD');

    return new PDO(
        "pgsql:host={$host};port={$port};dbname={$database}",
        $username,
        $password !== false ? $password : 'password',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

function baselinePrepare(PDO $connection): void
{
    $schema = 'zerno_baseline_' . bin2hex(random_bytes(12));
    $created = false;

    try {
        $connection->exec('CREATE SCHEMA "' . $schema . '"');
        // Ownership is recorded only after CREATE succeeds, so a collision cannot drop another schema.
        $created = true;
        $connection->exec('COMMENT ON SCHEMA "' . $schema . '" IS ' . $connection->quote(BASELINE_SCHEMA_MARKER));
        $connection->exec('CREATE TABLE "' . $schema . '".baseline_records (id BIGINT PRIMARY KEY, value TEXT NOT NULL)');
        $connection->exec('INSERT INTO "' . $schema . '".baseline_records (id, value) SELECT id, \'record-\' || id FROM generate_series(1, 10000) AS id');
        $connection->exec('ANALYZE "' . $schema . '".baseline_records');
        $statement = $connection->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id, value FROM "' . $schema . '".baseline_records WHERE id = :id');
        $statement->execute(['id' => 42]);
        $explain = $statement->fetchColumn();
        if (!is_string($explain)) {
            throw new RuntimeException('PostgreSQL did not return an EXPLAIN plan.');
        }
        $plan = json_decode($explain, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($plan) || !is_array($plan[0] ?? null) || !is_array($plan[0]['Plan'] ?? null)
            || !in_array($plan[0]['Plan']['Node Type'] ?? null, ['Index Scan', 'Index Only Scan'], true)
            || ($plan[0]['Plan']['Actual Rows'] ?? null) !== 1) {
            throw new RuntimeException('The baseline lookup must return one row through an index.');
        }
        fwrite(STDOUT, json_encode([
            'schema' => $schema,
            'row_count' => 10000,
            'lookup_id' => 42,
            'expected_body' => '{"id":42,"value":"record-42"}',
            'application_selects_per_request' => ['json' => 0, 'select' => 1],
            'sql_count_method' => 'Declared from the fixture handlers; excludes connection, search_path and prepare work.',
            'explain' => $plan,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    } catch (Throwable $exception) {
        if ($created) {
            $connection->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        }

        throw $exception;
    }
}

function baselineCleanup(PDO $connection, string $schema): void
{
    if (preg_match('/\Azerno_baseline_[a-f0-9]{24}\z/', $schema) !== 1) {
        throw new InvalidArgumentException('Refusing to drop a schema outside the baseline sandbox.');
    }
    $statement = $connection->prepare(
        'SELECT 1 FROM pg_namespace WHERE nspname = :schema'
        . ' AND nspowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)'
        . ' AND obj_description(oid, \'pg_namespace\') = :marker',
    );
    $statement->execute(['schema' => $schema, 'marker' => BASELINE_SCHEMA_MARKER]);
    if ($statement->fetchColumn() === false) {
        throw new RuntimeException('Refusing to drop a schema without the baseline marker and current-user ownership.');
    }
    $connection->exec('DROP SCHEMA "' . $schema . '" CASCADE');
    fwrite(STDOUT, json_encode(['removed_schema' => $schema], JSON_THROW_ON_ERROR) . "\n");
}

try {
    $arguments = $argv ?? [];
    $action = $arguments[1] ?? '';
    if ($action !== 'prepare' && $action !== 'cleanup') {
        throw new InvalidArgumentException('Usage: php load-tests/baseline/database.php prepare | cleanup <schema>');
    }
    $connection = baselineConnect();
    if ($action === 'prepare') {
        baselinePrepare($connection);
    } else {
        baselineCleanup($connection, $arguments[2] ?? '');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
