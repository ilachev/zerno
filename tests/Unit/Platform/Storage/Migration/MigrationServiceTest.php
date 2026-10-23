<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Storage\Migration;

use App\Platform\Storage\Migration\Migration;
use App\Platform\Storage\Migration\MigrationLoader;
use App\Platform\Storage\Migration\MigrationRepository;
use App\Platform\Storage\Migration\MigrationService;
use App\Platform\Storage\StorageException;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\SQLiteStorage;
use Tests\Fixtures\SqlMigration;
use Tests\Unit\Platform\Logging\TestLogger;

final class MigrationServiceTest extends TestCase
{
    private SQLiteStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new SQLiteStorage(':memory:');
    }

    public function testOrderRepeatAndReverseRollback(): void
    {
        $first = $this->migration('001', ['CREATE TABLE records (id INTEGER PRIMARY KEY)'], ['DROP TABLE records']);
        $second = $this->migration('002', ['CREATE INDEX records_id ON records (id)'], ['DROP INDEX records_id']);
        $service = $this->service($second, $first);
        $service->migrate();
        $service->migrate();
        self::assertSame([['version' => '001'], ['version' => '002']], $this->storage->query('SELECT version FROM migrations ORDER BY version'));
        $service->rollback();
        self::assertSame([], $this->storage->query('SELECT version FROM migrations'));
        self::assertSame([], $this->storage->query("SELECT name FROM sqlite_master WHERE name = 'records'"));
    }

    public function testStatementsPreserveSemicolonsInsideLiteralsAndComments(): void
    {
        $service = $this->service($this->migration('001', [
            'CREATE TABLE records (value TEXT)',
            "-- A comment containing ; is part of this statement.\nINSERT INTO records VALUES ('first;second')",
        ], ['DROP TABLE records']));
        $service->migrate();
        self::assertSame([['value' => 'first;second']], $this->storage->query('SELECT value FROM records'));
    }

    public function testEmptyListsOnlyChangeTheVersion(): void
    {
        $service = $this->service($this->migration('001', [], []));
        $service->migrate();
        self::assertSame([['version' => '001']], $this->storage->query('SELECT version FROM migrations'));
        $service->rollback();
        self::assertSame([], $this->storage->query('SELECT version FROM migrations'));
    }

    public function testFailedUpRollsBackDdlAndCanBeCorrected(): void
    {
        $service = $this->service($this->migration('001', [
            'CREATE TABLE records (id INTEGER)',
            'INVALID SQL',
        ], []));

        try {
            $service->migrate();
            self::fail('A partially applied migration must fail');
        } catch (StorageException) {
            self::assertSame([], $this->storage->query("SELECT name FROM sqlite_master WHERE name = 'records'"));
            self::assertSame([], $this->storage->query('SELECT version FROM migrations'));
        }
        $this->service($this->migration('001', ['CREATE TABLE records (id INTEGER)'], []))->migrate();
        self::assertSame([['version' => '001']], $this->storage->query('SELECT version FROM migrations'));
    }

    public function testFailedDownRestoresDdlAndKeepsTheVersion(): void
    {
        $service = $this->service($this->migration('001', ['CREATE TABLE records (id INTEGER)'], [
            'DROP TABLE records',
            'INVALID SQL',
        ]));
        $service->migrate();

        try {
            $service->rollback();
            self::fail('A partially reverted migration must fail');
        } catch (StorageException) {
            self::assertSame([['name' => 'records']], $this->storage->query("SELECT name FROM sqlite_master WHERE name = 'records'"));
            self::assertSame([['version' => '001']], $this->storage->query('SELECT version FROM migrations'));
        }
        $this->service($this->migration('001', [], ['DROP TABLE records']))->rollback();
        self::assertSame([], $this->storage->query('SELECT version FROM migrations'));
    }

    public function testDuplicateVersionsAreRejectedBeforeUpOrDown(): void
    {
        $first = $this->migration('001', ['CREATE TABLE records (id INTEGER)'], ['DROP TABLE records']);
        $second = $this->migration('001', ['CREATE TABLE other_records (id INTEGER)'], ['DROP TABLE other_records']);
        $service = $this->service($first, $second);
        foreach (['migrate', 'rollback'] as $action) {
            try {
                $service->{$action}();
                self::fail('Duplicate migration versions are ambiguous');
            } catch (\LogicException $exception) {
                self::assertSame('Duplicate migration version: 001', $exception->getMessage());
            }
        }
        self::assertSame([], $this->storage->query('SELECT version FROM migrations'));
        self::assertSame([], $this->storage->query("SELECT name FROM sqlite_master WHERE name IN ('records', 'other_records')"));
    }

    private function service(Migration ...$migrations): MigrationService
    {
        $service = new MigrationService($this->storage, new MigrationRepository($this->storage), new MigrationLoader('', new TestLogger()));
        foreach ($migrations as $migration) {
            $service->addMigration($migration);
        }

        return $service;
    }

    /** @param list<string> $up
     * @param list<string> $down
     */
    private function migration(string $version, array $up, array $down): Migration
    {
        return new SqlMigration($version, $up, $down);
    }
}
