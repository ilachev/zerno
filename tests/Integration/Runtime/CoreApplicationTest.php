<?php

declare(strict_types=1);

namespace Tests\Integration\Runtime;

use App\Platform\Logging\ReadableOutputLogger;
use App\Platform\Storage\Migration\MigrationLoader;
use App\Platform\Storage\Migration\MigrationRepository;
use App\Platform\Storage\Migration\MigrationService;
use App\Platform\Storage\Sql\ExecutableQuery;
use App\Platform\Storage\Sql\SqlExecutor;
use App\Platform\Storage\Storage;
use PHPUnit\Framework\TestCase;
use Tests\Integration\PostgreSqlTestDatabase;

final class CoreApplicationTest extends TestCase
{
    private PostgreSqlTestDatabase $database;

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->database = PostgreSqlTestDatabase::fromEnvironment();
        $this->database->create();
        foreach ($this->database->applicationEnvironment() as $name => $value) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->database->cleanup();
        } finally {
            foreach ($this->previousEnvironment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testCoreMigrationsDoNotInstallApiApplicationTables(): void
    {
        $storage = $this->storage();
        self::assertSame([], $storage->query(
            'SELECT tablename FROM pg_tables WHERE schemaname = :schema',
            ['schema' => $this->database->schema],
        ));

        $migrations = new MigrationService($storage, new MigrationRepository($storage), new MigrationLoader(\dirname(__DIR__, 3) . '/src/Migrations', new ReadableOutputLogger()));
        $migrations->loadMigrations();
        $migrations->migrate();

        self::assertSame([['tablename' => 'migrations']], $storage->query(
            'SELECT tablename FROM pg_tables WHERE schemaname = :schema ORDER BY tablename',
            ['schema' => $this->database->schema],
        ));
        self::assertSame([], $storage->query('SELECT version FROM migrations'));
    }

    public function testCorePdoSupportsBoundParametersAndTransactionRecovery(): void
    {
        $storage = $this->storage();
        $executor = new SqlExecutor($storage);
        $storage->execute('CREATE TEMP TABLE core_application_records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $insert = 'INSERT INTO core_application_records (id, value) VALUES (:id, :value)';
        $literal = "A quote ' and ; :placeholder -- remain data";

        $result = $storage->transaction(static function () use ($storage, $insert, $literal): string {
            $storage->execute($insert, ['id' => 1, 'value' => $literal]);
            $storage->execute($insert, ['id' => 2, 'value' => 'second']);

            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame([
            ['id' => 1, 'value' => $literal],
            ['id' => 2, 'value' => 'second'],
        ], $storage->query('SELECT id, value FROM core_application_records ORDER BY id'));

        $failure = new \DomainException('Rollback the transaction.');

        try {
            $storage->transaction(static function () use ($storage, $insert, $failure): void {
                $storage->execute($insert, ['id' => 3, 'value' => 'must roll back']);

                throw $failure;
            });
            self::fail('The transaction callback exception must be propagated.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame([], $storage->query('SELECT id FROM core_application_records WHERE id = :id', ['id' => 3]));

        $storage->transaction(static fn(): bool => $storage->execute($insert, ['id' => 4, 'value' => 'after rollback']));
        $query = new readonly class (4) implements ExecutableQuery {
            public function __construct(private int $id) {}

            public function sql(): string
            {
                return 'SELECT id, value FROM core_application_records WHERE id = :id';
            }

            /** @return array{id: int} */
            public function params(): array
            {
                return ['id' => $this->id];
            }
        };
        self::assertSame(['id' => 4, 'value' => 'after rollback'], $executor->fetchOne($query));
    }

    public function testTransactionCanOpenTheConnectionAndKeepsItsSession(): void
    {
        $storage = $this->storage();
        $first = $storage->transaction(static fn(): array => $storage->query('SELECT pg_backend_pid() AS pid'));
        self::assertSame($first, $storage->query('SELECT pg_backend_pid() AS pid'));
    }

    private function storage(): Storage
    {
        /** @var Storage $storage */
        $storage = require \dirname(__DIR__, 3) . '/config/storage.php';

        return $storage;
    }
}
