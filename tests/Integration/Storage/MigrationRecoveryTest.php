<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Platform\Storage\Migration\Migration;
use App\Platform\Storage\Migration\MigrationLoader;
use App\Platform\Storage\Migration\MigrationRepository;
use App\Platform\Storage\Migration\MigrationService;
use App\Platform\Storage\PostgreSQLStorage;
use App\Platform\Storage\StorageException;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\SqlMigration;
use Tests\Integration\PostgreSqlTestDatabase;
use Tests\Unit\Platform\Logging\TestLogger;

final class MigrationRecoveryTest extends TestCase
{
    private PostgreSqlTestDatabase $database;

    private PostgreSQLStorage $storage;

    private MigrationRepository $repository;

    protected function setUp(): void
    {
        $this->database = PostgreSqlTestDatabase::fromEnvironment();
        $this->database->create();
        $this->storage = new PostgreSQLStorage($this->database->host, $this->database->port, $this->database->database, $this->database->username, $this->database->password, $this->database->schema);
        $this->repository = new MigrationRepository($this->storage);
    }

    protected function tearDown(): void
    {
        $this->database->cleanup();
    }

    public function testFailedUpKeepsEarlierVersionsAndCanResumeAfterCorrection(): void
    {
        $first = new SqlMigration('001', ['CREATE TABLE first_records (id BIGINT)'], ['DROP TABLE first_records']);
        $failed = new SqlMigration('002', ['CREATE TABLE middle_records (id BIGINT)', 'ALTER TABLE missing_records ADD value TEXT'], []);
        $last = new SqlMigration('003', ['CREATE TABLE last_records (id BIGINT)'], ['DROP TABLE last_records']);
        $pid = $this->storage->queryOne('SELECT pg_backend_pid() AS pid');
        $this->fails(fn() => $this->service($last, $failed, $first)->migrate(), '42P01');
        self::assertSame(['001'], $this->repository->getExecutedMigrations());
        self::assertTrue($this->exists('first_records'));
        self::assertFalse($this->exists('middle_records'));
        self::assertFalse($this->exists('last_records'));
        $fixed = new SqlMigration('002', ['CREATE TABLE middle_records (id BIGINT)'], ['DROP TABLE middle_records']);
        $this->service($last, $fixed, $first)->migrate();
        self::assertSame(['001', '002', '003'], $this->repository->getExecutedMigrations());
        self::assertTrue($this->exists('middle_records'));
        self::assertTrue($this->exists('last_records'));
        self::assertSame($pid, $this->storage->queryOne('SELECT pg_backend_pid() AS pid'));
    }

    public function testFailedDownRestoresItsDdlDataAndVersionAfterANewerRollbackCommitted(): void
    {
        $first = new SqlMigration('001', ['CREATE TABLE first_records (id BIGINT)'], ['DROP TABLE first_records']);
        $middle = new SqlMigration('002', [
            'CREATE TABLE middle_records (value TEXT)',
            "INSERT INTO middle_records VALUES ('first;second')",
        ], ['DROP TABLE middle_records', 'ALTER TABLE missing_records ADD value TEXT']);
        $last = new SqlMigration('003', ['CREATE TABLE last_records (id BIGINT)'], ['DROP TABLE last_records']);
        $service = $this->service($first, $middle, $last);
        $service->migrate();
        $this->fails($service->rollback(...), '42P01');
        self::assertSame(['001', '002'], $this->repository->getExecutedMigrations());
        self::assertTrue($this->exists('first_records'));
        self::assertSame([['value' => 'first;second']], $this->storage->query('SELECT value FROM middle_records'));
        self::assertFalse($this->exists('last_records'));
        $fixed = new SqlMigration('002', [], ['DROP TABLE middle_records']);
        $this->service($first, $fixed, $last)->rollback();
        self::assertSame([], $this->repository->getExecutedMigrations());
        self::assertFalse($this->exists('first_records'));
        self::assertFalse($this->exists('middle_records'));
    }

    public function testVersionInsertFailureRollsBackDdlAndRetryIsExplicit(): void
    {
        $this->storage->execute("ALTER TABLE migrations ADD CONSTRAINT reject_version CHECK (version <> '001')");
        $service = $this->service(new SqlMigration('001', ['CREATE TABLE records (id BIGINT)'], ['DROP TABLE records']));
        $this->fails($service->migrate(...), '23514');
        self::assertFalse($this->exists('records'));
        self::assertSame([], $this->repository->getExecutedMigrations());
        $this->storage->execute('ALTER TABLE migrations DROP CONSTRAINT reject_version');
        $service->migrate();
        self::assertTrue($this->exists('records'));
        self::assertSame(['001'], $this->repository->getExecutedMigrations());
    }

    public function testVersionDeleteFailureRestoresTheDroppedTable(): void
    {
        $service = $this->service(new SqlMigration('001', ['CREATE TABLE records (id BIGINT)'], ['DROP TABLE records']));
        $service->migrate();
        $this->storage->execute('CREATE TABLE version_guards (version TEXT REFERENCES migrations(version))');
        $this->storage->execute("INSERT INTO version_guards VALUES ('001')");
        $this->fails($service->rollback(...), '23503');
        self::assertTrue($this->exists('records'));
        self::assertSame(['001'], $this->repository->getExecutedMigrations());
        $this->storage->execute('DROP TABLE version_guards');
        $service->rollback();
        self::assertFalse($this->exists('records'));
        self::assertSame([], $this->repository->getExecutedMigrations());
    }

    public function testCommitFailureRollsBackBothDdlAndTheInsertedVersion(): void
    {
        $create = 'CREATE TABLE records (id BIGINT UNIQUE DEFERRABLE INITIALLY DEFERRED)';
        $service = $this->service(new SqlMigration('001', [$create, 'INSERT INTO records VALUES (1), (1)'], []));

        try {
            $service->migrate();
            self::fail('The migration commit must check its deferred constraints');
        } catch (\PDOException $failure) {
            self::assertSame('23505', $failure->getCode());
        }
        self::assertFalse($this->exists('records'));
        self::assertSame([], $this->repository->getExecutedMigrations());
        $this->service(new SqlMigration('001', [$create, 'INSERT INTO records VALUES (1), (2)'], []))->migrate();
        self::assertSame(['001'], $this->repository->getExecutedMigrations());
        self::assertSame([['id' => 1], ['id' => 2]], $this->storage->query('SELECT id FROM records ORDER BY id'));
    }

    public function testSqlStatementsKeepLiteralsCommentsAndDollarQuotedBodiesIntact(): void
    {
        $service = $this->service(new SqlMigration('001', [
            'CREATE TABLE records (value TEXT)',
            <<<'SQL'
                DO $body$
                BEGIN
                    INSERT INTO records VALUES ('first;second');
                    PERFORM 1;
                END
                $body$;
                SQL,
            "-- A comment containing ; is not a separator.\nINSERT INTO records VALUES ('third;fourth')",
        ], ['DROP TABLE records']));
        $service->migrate();
        self::assertSame([['value' => 'first;second'], ['value' => 'third;fourth']], $this->storage->query('SELECT value FROM records ORDER BY value'));
        $service->rollback();
        self::assertFalse($this->exists('records'));
    }

    public function testDuplicateVersionsCannotRunAnUnappliedDown(): void
    {
        $service = $this->service(new SqlMigration('001', ['CREATE TABLE records (id BIGINT)'], ['DROP TABLE records']));
        $service->migrate();
        $service->addMigration(new SqlMigration('001', [], ['DROP TABLE migrations']));
        foreach (['migrate', 'rollback'] as $action) {
            try {
                $service->{$action}();
                self::fail('Duplicate versions must fail before any migration action');
            } catch (\LogicException $exception) {
                self::assertSame('Duplicate migration version: 001', $exception->getMessage());
            }
        }
        self::assertTrue($this->exists('records'));
        self::assertSame(['001'], $this->repository->getExecutedMigrations());
    }

    public function testNonTransactionalDdlFailsWithoutLeavingEarlierStatementsBehind(): void
    {
        $service = $this->service(new SqlMigration('001', [
            'CREATE TABLE records (id BIGINT)',
            'CREATE INDEX CONCURRENTLY records_id ON records (id)',
        ], []));
        $this->fails($service->migrate(...), '25001');
        self::assertFalse($this->exists('records'));
        self::assertSame([], $this->repository->getExecutedMigrations());
    }

    private function service(Migration ...$migrations): MigrationService
    {
        $service = new MigrationService($this->storage, $this->repository, new MigrationLoader('', new TestLogger()));
        foreach ($migrations as $migration) {
            $service->addMigration($migration);
        }

        return $service;
    }

    /** @phpstan-impure */
    private function exists(string $table): bool
    {
        return ($this->storage->queryOne('SELECT to_regclass(:table) IS NOT NULL AS present', ['table' => $table])['present'] ?? false) === true;
    }

    private function fails(callable $operation, string $state): void
    {
        try {
            $operation();
            self::fail('The SQL operation must fail');
        } catch (StorageException $exception) {
            self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
            self::assertSame($state, $exception->getPrevious()->getCode());
        }
    }
}
