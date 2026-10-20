<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Platform\Storage\PostgreSQLStorage;
use PHPUnit\Framework\TestCase;
use Tests\Integration\PostgreSqlTestDatabase;

final class DatabaseIsolationTest extends TestCase
{
    public function testCleanupPreservesPublicDataAndOtherRuns(): void
    {
        $first = PostgreSqlTestDatabase::fromEnvironment();
        $second = PostgreSqlTestDatabase::fromEnvironment();
        $observer = $first->connect();
        $sentinel = $first->schema . '_sentinel';
        $observer->exec('CREATE TABLE public."' . $sentinel . '" (value TEXT NOT NULL)');

        try {
            $observer->exec('INSERT INTO public."' . $sentinel . '" VALUES (\'preserved\')');
            $first->create();
            $second->create();
            $observer->exec('CREATE TABLE "' . $first->schema . '".sandbox_table (id BIGINT)');
            $first->cleanup();
            $first->cleanup();

            $sentinelRows = $observer->query('SELECT value FROM public."' . $sentinel . '"');
            self::assertNotFalse($sentinelRows);
            self::assertSame('preserved', $sentinelRows->fetchColumn());

            $statement = $observer->prepare('SELECT COUNT(*) FROM pg_namespace WHERE nspname = :schema');
            $statement->execute(['schema' => $first->schema]);
            self::assertSame(0, $statement->fetchColumn());
            $statement->execute(['schema' => $second->schema]);
            self::assertSame(1, $statement->fetchColumn());
        } finally {
            $first->cleanup();
            $second->cleanup();
            $observer->exec('DROP TABLE public."' . $sentinel . '"');
        }
    }

    public function testConfiguredSchemaCannotAddPublicToSearchPath(): void
    {
        $database = PostgreSqlTestDatabase::fromEnvironment();
        $storage = new PostgreSQLStorage(
            host: $database->host,
            port: $database->port,
            dbname: $database->database,
            username: $database->username,
            password: $database->password,
            schema: $database->schema . ', public',
        );

        self::assertSame([['schema' => null]], $storage->query('SELECT current_schema() AS schema'));
    }

    public function testBootstrapPreservesExistingPublicData(): void
    {
        $database = PostgreSqlTestDatabase::fromEnvironment();
        $observer = $database->connect();
        $sentinel = $database->schema . '_sentinel';
        $observer->exec('CREATE TABLE public."' . $sentinel . '" (value TEXT NOT NULL)');

        try {
            $observer->exec('INSERT INTO public."' . $sentinel . '" VALUES (\'preserved\')');
            $environment = getenv();
            $environment['DB_NAME'] = 'application_database_must_not_be_used';
            $environment['DB_SCHEMA'] = 'public';

            $process = proc_open(
                [PHP_BINARY, \dirname(__DIR__) . '/bootstrap.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                env_vars: $environment,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output . $errors);

            $sentinelRows = $observer->query('SELECT value FROM public."' . $sentinel . '"');
            self::assertNotFalse($sentinelRows);
            self::assertSame('preserved', $sentinelRows->fetchColumn());
        } finally {
            $observer->exec('DROP TABLE IF EXISTS public."' . $sentinel . '"');
        }
    }
}
