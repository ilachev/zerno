<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Platform\Storage\Migration\MigrationRepository;
use App\Platform\Storage\PostgreSQLStorage;
use PHPUnit\Framework\TestCase;
use Tests\Integration\PostgreSqlTestDatabase;

final class MigrationCliRecoveryTest extends TestCase
{
    public function testNewMetadataAcceptsBigintTimestamps(): void
    {
        $database = PostgreSqlTestDatabase::fromEnvironment();
        $database->create();

        try {
            $storage = new PostgreSQLStorage($database->host, $database->port, $database->database, $database->username, $database->password, $database->schema);
            new MigrationRepository($storage);
            $storage->execute('INSERT INTO migrations (version, executed_at) VALUES (:version, :time)', ['version' => 'future', 'time' => PHP_INT_MAX]);
            self::assertSame(['executed_at' => PHP_INT_MAX], $storage->queryOne('SELECT executed_at FROM migrations WHERE version = :version', ['version' => 'future']));
        } finally {
            $database->cleanup();
        }
    }

    public function testExplicitMetadataUpgradePreservesRowsAndCanBeRepeated(): void
    {
        $database = PostgreSqlTestDatabase::fromEnvironment();
        $database->create();

        try {
            $storage = new PostgreSQLStorage($database->host, $database->port, $database->database, $database->username, $database->password, $database->schema);
            $storage->execute('CREATE TABLE migrations (version TEXT PRIMARY KEY, executed_at INTEGER NOT NULL)');
            $storage->execute('INSERT INTO migrations (version, executed_at) VALUES (:version, :time)', ['version' => 'existing', 'time' => 2147483647]);
            $repository = new MigrationRepository($storage);
            $columnSql = 'SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table AND column_name = :column';
            $columnParams = ['schema' => $database->schema, 'table' => 'migrations', 'column' => 'executed_at'];
            self::assertSame(['data_type' => 'integer'], $storage->queryOne($columnSql, $columnParams));

            [$status, $output] = $this->runCli($database, null, 'upgrade-metadata');
            self::assertSame(0, $status, $output);
            // A completed CLI upgrade is followed by a fresh graph, not cached integer parameter plans.
            $storage = new PostgreSQLStorage($database->host, $database->port, $database->database, $database->username, $database->password, $database->schema);
            $repository = new MigrationRepository($storage);
            self::assertSame(['data_type' => 'bigint'], $storage->queryOne($columnSql, $columnParams));
            self::assertSame([['version' => 'existing', 'executed_at' => 2147483647]], $storage->query('SELECT version, executed_at FROM migrations'));
            $storage->execute('INSERT INTO migrations (version, executed_at) VALUES (:version, :time)', ['version' => 'future', 'time' => PHP_INT_MAX]);
            $repository->add('after-upgrade');

            [$status, $output] = $this->runCli($database, null, 'upgrade-metadata');
            self::assertSame(0, $status, $output);
            self::assertSame(['executed_at' => PHP_INT_MAX], $storage->queryOne('SELECT executed_at FROM migrations WHERE version = :version', ['version' => 'future']));
            self::assertCount(3, $storage->query('SELECT version FROM migrations'));
        } finally {
            $database->cleanup();
        }
    }

    public function testFailedMetadataUpgradePreservesTheExistingTable(): void
    {
        $database = PostgreSqlTestDatabase::fromEnvironment();
        $database->create();

        try {
            $storage = new PostgreSQLStorage($database->host, $database->port, $database->database, $database->username, $database->password, $database->schema);
            $storage->execute('CREATE TABLE migrations (version TEXT PRIMARY KEY, executed_at TEXT NOT NULL)');
            $storage->execute('INSERT INTO migrations (version, executed_at) VALUES (:version, :time)', ['version' => 'invalid', 'time' => 'not a timestamp']);
            [$status, $output] = $this->runCli($database, null, 'upgrade-metadata');
            self::assertNotSame(0, $status);
            self::assertStringContainsString('42804', $output);
            self::assertSame([['version' => 'invalid', 'executed_at' => 'not a timestamp']], $storage->query('SELECT version, executed_at FROM migrations'));
        } finally {
            $database->cleanup();
        }
    }

    public function testASeparateCliRunResumesAfterCorrectingUpAndDown(): void
    {
        $database = PostgreSqlTestDatabase::fromEnvironment();
        $database->create();
        $directory = sys_get_temp_dir() . '/zerno-migration-cli-' . bin2hex(random_bytes(12));

        try {
            self::assertTrue(mkdir($directory, 0o700));
            $storage = new PostgreSQLStorage($database->host, $database->port, $database->database, $database->username, $database->password, $database->schema);
            $first = $directory . '/Migration20300101001_First.php';
            $second = $directory . '/Migration20300101002_Second.php';
            $this->writeMigration($first, 'Migration20300101001_First', ['CREATE TABLE first_records (id BIGINT)'], ['DROP TABLE first_records']);
            $this->writeMigration($second, 'Migration20300101002_Second', ['CREATE TABLE second_records (id BIGINT)', 'SELECT 1 / 0'], ['DROP TABLE second_records']);
            [$status, $output] = $this->runCli($database, $directory, 'migrate');
            self::assertNotSame(0, $status);
            self::assertStringContainsString('22012', $output);
            self::assertSame([['version' => 'Migration20300101001_First']], $storage->query('SELECT version FROM migrations ORDER BY version'));
            self::assertSame(['present' => false], $storage->queryOne("SELECT to_regclass('second_records') IS NOT NULL AS present"));

            $this->writeMigration($second, 'Migration20300101002_Second', ['CREATE TABLE second_records (id BIGINT)'], ['DROP TABLE second_records', 'SELECT 1 / 0']);
            [$status, $output] = $this->runCli($database, $directory, 'migrate');
            self::assertSame(0, $status, $output);
            self::assertCount(2, $storage->query('SELECT version FROM migrations'));
            [$status, $output] = $this->runCli($database, $directory, 'rollback');
            self::assertNotSame(0, $status);
            self::assertStringContainsString('22012', $output);
            self::assertCount(2, $storage->query('SELECT version FROM migrations'));
            self::assertSame(['present' => true], $storage->queryOne("SELECT to_regclass('second_records') IS NOT NULL AS present"));

            $this->writeMigration($second, 'Migration20300101002_Second', ['CREATE TABLE second_records (id BIGINT)'], ['DROP TABLE second_records']);
            [$status, $output] = $this->runCli($database, $directory, 'rollback');
            self::assertSame(0, $status, $output);
            self::assertSame([], $storage->query('SELECT version FROM migrations'));
            self::assertSame(['present' => false], $storage->queryOne("SELECT to_regclass('first_records') IS NOT NULL AS present"));
            self::assertSame(['present' => false], $storage->queryOne("SELECT to_regclass('second_records') IS NOT NULL AS present"));
        } finally {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            $database->cleanup();
        }
    }

    /** @param list<string> $up
     * @param list<string> $down
     */
    private function writeMigration(string $path, string $class, array $up, array $down): void
    {
        $source = '<?php declare(strict_types=1); namespace CliMigrationFixture; final readonly class ' . $class
            . ' extends \App\Platform\Storage\Migration\AbstractMigration {'
            . ' public function up(): array { return ' . var_export($up, true) . '; }'
            . ' public function down(): array { return ' . var_export($down, true) . '; }}';
        self::assertSame(\strlen($source), file_put_contents($path, $source));
    }

    /** @return array{int, string} */
    private function runCli(PostgreSqlTestDatabase $database, ?string $directory, string $action): array
    {
        $errors = tmpfile();
        if ($errors === false) {
            throw new \RuntimeException('Cannot create the migration CLI error log');
        }

        try {
            $process = proc_open(
                [PHP_BINARY, 'bin/migrate.php', $action, ...($directory === null ? [] : [$directory])],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errors],
                $pipes,
                \dirname(__DIR__, 3),
                array_replace(getenv(), $database->applicationEnvironment()),
            );
            if (!\is_resource($process)) {
                throw new \RuntimeException('Cannot start the migration CLI');
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            rewind($errors);
            $errorOutput = stream_get_contents($errors);
            if ($output === false || $errorOutput === false) {
                throw new \RuntimeException('Cannot read the migration CLI output');
            }

            return [$status, $output . $errorOutput];
        } finally {
            fclose($errors);
        }
    }
}
