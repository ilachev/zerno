<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Platform\Storage\PostgreSQLStorage;
use App\Platform\Storage\StorageException;
use PHPUnit\Framework\TestCase;
use Tests\Integration\PostgreSqlTestDatabase;

final class QueryOneTest extends TestCase
{
    private PostgreSqlTestDatabase $database;

    private PostgreSQLStorage $storage;

    protected function setUp(): void
    {
        $this->database = PostgreSqlTestDatabase::fromEnvironment();
        $this->database->create();
        $this->storage = new PostgreSQLStorage(
            $this->database->host,
            $this->database->port,
            $this->database->database,
            $this->database->username,
            $this->database->password,
            $this->database->schema,
        );
    }

    protected function tearDown(): void
    {
        $this->database->cleanup();
    }

    public function testFirstOperationSupportsEmptyOneAndManyRows(): void
    {
        $sql = 'SELECT id FROM generate_series(1, CAST(:count AS INTEGER)) AS records(id) ORDER BY id DESC';
        foreach ([0, 1, 3, 1, 0, 3] as $count) {
            self::assertSame($count === 0 ? null : ['id' => $count], $this->storage->queryOne($sql, ['count' => $count]));
        }
    }

    public function testNullAndFalsyColumnsAreDistinctFromAnAbsentRow(): void
    {
        self::assertSame([
            'missing' => null,
            'disabled' => false,
            'zero' => 0,
            'empty' => '',
            'enabled' => true,
            'maximum' => PHP_INT_MAX,
            'fraction' => 1.5,
        ], $this->storage->queryOne("SELECT NULL::TEXT AS missing, FALSE AS disabled, 0 AS zero, ''::TEXT AS empty, TRUE AS enabled, 9223372036854775807::BIGINT AS maximum, 1.5::DOUBLE PRECISION AS fraction"));
        self::assertSame(['missing' => null], $this->storage->queryOne('SELECT NULL::TEXT AS missing'));
    }

    public function testPartialReadsReuseTheSameStatementAndConnection(): void
    {
        $sql = 'SELECT id FROM generate_series(1, 3) AS records(id) WHERE id >= CAST(:minimum AS INTEGER) ORDER BY id';
        $pid = $this->storage->queryOne('SELECT pg_backend_pid() AS pid');
        foreach ([1, 2, 4, 3, 1] as $minimum) {
            self::assertSame($minimum > 3 ? null : ['id' => $minimum], $this->storage->queryOne($sql, ['minimum' => $minimum]));
            self::assertSame($minimum > 3 ? [] : array_map(static fn(int $id): array => ['id' => $id], range($minimum, 3)), $this->storage->query($sql, ['minimum' => $minimum]));
            self::assertSame($pid, $this->storage->queryOne('SELECT pg_backend_pid() AS pid'));
        }
        $prepared = $this->storage->query('SELECT statement FROM pg_prepared_statements');
        self::assertCount(1, array_filter($prepared, static fn(array $row): bool => $row['statement'] === str_replace(':minimum', '$1', $sql)));
    }

    public function testReturningStillChangesEveryMatchingRow(): void
    {
        $this->storage->execute('CREATE TABLE records (id BIGINT PRIMARY KEY, value TEXT NOT NULL)');
        $this->storage->execute("INSERT INTO records (id, value) VALUES (1, 'old'), (2, 'old'), (3, 'old')");
        $sql = 'UPDATE records SET value = :value RETURNING id';
        foreach (["A quote ' and ; -- remain data", 'next value'] as $value) {
            $row = $this->storage->queryOne($sql, ['value' => $value]);
            self::assertNotNull($row);
            self::assertContains($row['id'], [1, 2, 3]);
            self::assertSame([
                ['id' => 1, 'value' => $value],
                ['id' => 2, 'value' => $value],
                ['id' => 3, 'value' => $value],
            ], $this->storage->query('SELECT id, value FROM records ORDER BY id'));
        }
        self::assertNull($this->storage->queryOne('UPDATE records SET value = :value WHERE id = 4 RETURNING id', ['value' => 'absent']));
    }

    public function testInvalidSqlDoesNotBecomeAnAbsentRow(): void
    {
        try {
            $this->storage->queryOne('SELECT missing_column');
            self::fail('Invalid SQL must fail');
        } catch (StorageException $exception) {
            self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
            self::assertSame('42703', $exception->getPrevious()->getCode());
        }
        self::assertSame(['id' => 1], $this->storage->queryOne('SELECT 1 AS id'));
    }

    public function testErrorBeyondTheFirstRowFailsAndTheStatementRemainsReusable(): void
    {
        $sql = 'SELECT 12 / (CAST(:divisor AS INTEGER) - id) AS quotient FROM generate_series(1, 3) AS records(id) ORDER BY id';

        try {
            $this->storage->queryOne($sql, ['divisor' => 3]);
            self::fail('A later division by zero must not be hidden by a successful first row');
        } catch (StorageException $exception) {
            self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
            self::assertSame('22012', $exception->getPrevious()->getCode());
        }
        self::assertSame(['quotient' => 4], $this->storage->queryOne($sql, ['divisor' => 4]));
        self::assertSame([['quotient' => 4], ['quotient' => 6], ['quotient' => 12]], $this->storage->query($sql, ['divisor' => 4]));
    }

    public function testLargeResultDoesNotBecomeALargePhpArray(): void
    {
        $sql = "SELECT id, repeat('x', 1024) AS payload FROM generate_series(1, CAST(:count AS INTEGER)) AS records(id) ORDER BY id";
        $this->storage->queryOne($sql, ['count' => 1]);
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $row = $this->storage->queryOne($sql, ['count' => 20_000]);
        $peak = memory_get_peak_usage() - $before;
        self::assertSame(['id' => 1, 'payload' => str_repeat('x', 1024)], $row);
        // This observes Zend allocations, not the native libpq buffer or process RSS.
        self::assertLessThan(2_097_152, $peak, 'Only one result row should be materialized as PHP values');
    }
}
