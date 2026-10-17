#!/usr/bin/env php
<?php

declare(strict_types=1);

use SqlGen\Check\PostgreSqlConnectionConfig;
use SqlGen\Check\PostgreSqlQueryChecker;

require_once __DIR__ . '/../bootstrap.php';

$options = getopt('', [
    'input-dir:',
    'schema:',
    'db-host::',
    'db-port::',
    'db-name::',
    'db-user::',
    'db-password::',
]);

if (!is_array($options)) {
    fwrite(STDERR, "Failed to read options.\n");
    exit(1);
}

$inputDir = $options['input-dir'] ?? null;
$schemaPath = $options['schema'] ?? null;

if (!is_string($inputDir) || !is_string($schemaPath)) {
    fwrite(
        STDERR,
        "Usage: sql-check-pg.php --input-dir=sql/queries --schema=sql/schema.sql [--db-host=localhost --db-port=5432 --db-name=app --db-user=app --db-password=password]\n",
    );
    exit(1);
}

$connectionConfig = new PostgreSqlConnectionConfig(
    host: is_string($options['db-host'] ?? null) ? $options['db-host'] : (getenv('DB_HOST') ?: 'localhost'),
    port: (int) (is_string($options['db-port'] ?? null) ? $options['db-port'] : (getenv('DB_PORT') ?: '5432')),
    database: is_string($options['db-name'] ?? null) ? $options['db-name'] : (getenv('DB_NAME') ?: 'app'),
    username: is_string($options['db-user'] ?? null) ? $options['db-user'] : (getenv('DB_USER') ?: 'app'),
    password: is_string($options['db-password'] ?? null) ? $options['db-password'] : (getenv('DB_PASSWORD') ?: 'password'),
);

try {
    $checker = new PostgreSqlQueryChecker();
    $checker->assertQueriesAreValid($inputDir, $schemaPath, $connectionConfig);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
