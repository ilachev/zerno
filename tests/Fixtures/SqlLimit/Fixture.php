<?php

declare(strict_types=1);

namespace Tests\Fixtures\SqlLimit;

use App\Platform\Storage\PostgreSQLStorage;
use App\Platform\Storage\Sql\ExecutableQuery;
use App\Platform\Storage\Storage;
use Tests\Integration\PostgreSqlTestDatabase;

final readonly class Fixture
{
    public string $outputDirectory;

    public string $namespace;

    public int $generationNanoseconds;

    public function __construct()
    {
        $suffix = bin2hex(random_bytes(12));
        $this->outputDirectory = sys_get_temp_dir() . '/zerno-sql-limit-' . $suffix;
        $this->namespace = 'Tests\Fixtures\SqlLimit\Generated' . $suffix;

        try {
            $generationStarted = hrtime(true);
            self::run([
                PHP_BINARY,
                \dirname(__DIR__, 3) . '/tools/sql-gen/bin/sql-gen.php',
                '--input-dir=' . __DIR__ . '/queries',
                '--output-dir=' . $this->outputDirectory,
                '--namespace=' . $this->namespace,
                '--schema=' . __DIR__ . '/schema.sql',
            ]);
            $this->generationNanoseconds = hrtime(true) - $generationStarted;
            foreach (glob($this->outputDirectory . '/Catalog/*.php') ?: [] as $file) {
                require $file;
            }
        } catch (\Throwable $exception) {
            $this->cleanup();

            throw $exception;
        }
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return ExecutableQuery<array<string, scalar|null>>
     */
    public function query(string $name, array $parameters): ExecutableQuery
    {
        $class = $this->namespace . '\Catalog\\' . $name . 'Query';
        if (!class_exists($class)) {
            throw new \RuntimeException('The fixture query was not generated: ' . $class);
        }
        $query = new $class(...$parameters);
        if (!$query instanceof ExecutableQuery) {
            throw new \RuntimeException('The generated query does not implement the runtime SQL contract.');
        }

        return $query;
    }

    public static function install(PostgreSqlTestDatabase $database): PostgreSQLStorage
    {
        $connection = $database->connect();
        $connection->exec('SET search_path TO "' . $database->schema . '"');
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new \RuntimeException('Cannot read the LIMIT fixture schema.');
        }
        $connection->exec($schema);

        return self::storage($database, $database->schema);
    }

    public static function storage(PostgreSqlTestDatabase $database, string $schema): PostgreSQLStorage
    {
        return new PostgreSQLStorage(
            host: $database->host,
            port: $database->port,
            dbname: $database->database,
            username: $database->username,
            password: $database->password,
            schema: $schema,
        );
    }

    public static function seed(Storage $storage, int $count): void
    {
        $storage->execute(
            <<<'SQL'
                INSERT INTO catalog_documents
                    (id, title, description, status, published_at, storage_key, original_name, file_size)
                SELECT id, 'Document ' || id, repeat('description ', 86), 'published',
                    1792195200 + id, 'document-' || id || '.pdf', 'report.pdf', 4096
                FROM generate_series(1, :count) AS fixture(id)
                SQL,
            ['count' => $count],
        );
    }

    public function cleanup(): void
    {
        if (is_dir($this->outputDirectory)) {
            self::removeDirectory($this->outputDirectory);
        }
    }

    /** @param non-empty-list<string> $command */
    public static function run(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start the LIMIT fixture subprocess.');
        }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('The LIMIT fixture subprocess failed: ' . $output . $errors);
        }
        if ($output === false) {
            throw new \RuntimeException('Cannot read the LIMIT fixture subprocess output.');
        }

        return $output;
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                throw new \RuntimeException('Cannot inspect a temporary generated SQL file.');
            }
            if ($entry->isDir()) {
                self::removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
