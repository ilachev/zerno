<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Platform\Storage\PostgreSQLStorage;
use App\Platform\Storage\Sql\ManyRowsQuery;
use App\Platform\Storage\Sql\OneRowQuery;
use App\Platform\Storage\Sql\SqlExecutor;
use App\Platform\Storage\StorageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\SqlLimit\Fixture;
use Tests\Integration\PostgreSqlTestDatabase;

final class SqlLimitTest extends TestCase
{
    private static Fixture $fixture;

    private PostgreSqlTestDatabase $database;

    private PostgreSQLStorage $storage;

    private SqlExecutor $executor;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = new Fixture();
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture->cleanup();
    }

    protected function setUp(): void
    {
        $this->database = PostgreSqlTestDatabase::fromEnvironment();
        $this->database->create();

        try {
            $this->storage = Fixture::install($this->database);
            $this->executor = new SqlExecutor($this->storage);
        } catch (\Throwable $exception) {
            $this->database->cleanup();

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        $this->database->cleanup();
    }

    #[DataProvider('provideLiteralLimitBoundsTheDatabaseResultCases')]
    public function testLiteralLimitBoundsTheDatabaseResult(int $count): void
    {
        Fixture::seed($this->storage, $count);
        $query = self::$fixture->query('ListFirstPage', ['status' => 'published']);
        self::assertInstanceOf(ManyRowsQuery::class, $query);
        self::assertStringContainsString('LIMIT 21', $query->sql());
        self::assertSame(['status' => 'published'], $query->params());
        $rows = $this->executor->fetchAll($query);
        self::assertCount(min(21, $count), $rows);
        self::assertSame($count === 0 ? [] : \array_slice(range($count, 1), 0, 21), array_column($rows, 'id'));
        self::assertSame($rows, $this->executor->fetchAll($query));
        self::assertCount(min(21, $count), $this->executor->fetchAllRows($query));
    }

    /** @return iterable<string, array{int}> */
    public static function provideLiteralLimitBoundsTheDatabaseResultCases(): iterable
    {
        yield 'empty' => [0];
        yield 'one row' => [1];
        yield 'full visible page' => [20];
        yield 'lookahead row' => [21];
        yield 'more than the SQL bound' => [22];
    }

    public function testPublishedCursorPagesRetainStableDescendingOrder(): void
    {
        Fixture::seed($this->storage, 47);
        $this->storage->execute('UPDATE catalog_documents SET status = :status WHERE id = :id', ['status' => 'draft', 'id' => 46]);
        $this->storage->execute('UPDATE catalog_documents SET status = :status WHERE id = :id', ['status' => 'withdrawn', 'id' => 47]);
        $first = $this->executor->fetchAll(self::$fixture->query('ListFirstPage', ['status' => 'published']));
        self::assertSame(range(45, 25), array_column($first, 'id'));
        $secondQuery = self::$fixture->query('ListBefore', ['status' => 'published', 'before' => 26]);
        $second = $this->executor->fetchAll($secondQuery);
        self::assertSame(range(25, 5), array_column($second, 'id'));
        self::assertSame($second, $this->executor->fetchAll($secondQuery));
        $last = $this->executor->fetchAll(self::$fixture->query('ListBefore', ['status' => 'published', 'before' => 6]));
        self::assertSame(range(5, 1), array_column($last, 'id'));
        $visible = [...\array_slice($first, 0, 20), ...\array_slice($second, 0, 20), ...$last];
        self::assertSame(range(45, 1), array_column($visible, 'id'));
        self::assertSame([], $this->executor->fetchAll(self::$fixture->query('ListBefore', ['status' => 'published', 'before' => 1])));
        self::assertSame([], $this->executor->fetchAll(self::$fixture->query('ListFirstPage', ['status' => "published' OR 1=1 --"])));
    }

    public function testParameterizedLimitIsANonNullableIntegerAndReachesPostgreSql(): void
    {
        Fixture::seed($this->storage, 22);
        $query = self::$fixture->query('ListBounded', ['status' => 'published', 'before' => 20, 'limit' => 3]);
        self::assertSame(['status' => 'published', 'before' => 20, 'limit' => 3], $query->params());
        self::assertStringContainsString('LIMIT :limit', $query->sql());
        $constructor = new \ReflectionMethod($query, '__construct');
        $limit = $constructor->getParameters()[2];
        self::assertSame('limit', $limit->getName());
        $type = $limit->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('int', $type->getName());
        self::assertFalse($type->allowsNull());
        self::assertSame([19, 18, 17], array_column($this->executor->fetchAll($query), 'id'));
        self::assertSame([], $this->executor->fetchAll(self::$fixture->query('ListBounded', ['status' => 'published', 'before' => 20, 'limit' => 0])));
        self::assertCount(22, $this->executor->fetchAll(self::$fixture->query('ListBounded', ['status' => 'published', 'before' => PHP_INT_MAX, 'limit' => PHP_INT_MAX])));

        try {
            $this->executor->fetchAll(self::$fixture->query('ListBounded', ['status' => 'published', 'before' => 20, 'limit' => -1]));
            self::fail('PostgreSQL must reject a negative LIMIT.');
        } catch (StorageException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(\PDOException::class, $previous);
            self::assertSame('2201W', $previous->getCode());
        }
        self::assertSame([19], array_column($this->executor->fetchAll(self::$fixture->query('ListBounded', ['status' => 'published', 'before' => 20, 'limit' => 1])), 'id'));
    }

    #[DataProvider('provideGeneratedConstructorRejectsNonIntegerLimitsCases')]
    public function testGeneratedConstructorRejectsNonIntegerLimits(null|int|string|float|bool $limit): void
    {
        $this->expectException(\TypeError::class);
        self::$fixture->query('ListBounded', ['status' => 'published', 'before' => 20, 'limit' => $limit]);
    }

    /** @return iterable<string, array{scalar|null}> */
    public static function provideGeneratedConstructorRejectsNonIntegerLimitsCases(): iterable
    {
        yield 'null' => [null];
        yield 'numeric text' => ['21'];
        yield 'fraction' => [1.5];
    }

    #[DataProvider('provideReusedLimitMatchesNativePostgreSqlTypeCases')]
    public function testReusedLimitMatchesNativePostgreSqlType(string $name, string $expectedType, bool $acceptsMaximum): void
    {
        $this->storage->execute('INSERT INTO limit_bounds (narrow, wide) VALUES (1, 1), (2, 2), (3, 3)');
        $query = self::$fixture->query($name, ['limit' => 3]);
        self::assertSame([2, 1], array_column($this->executor->fetchAll($query), 'narrow'));

        $nativeSql = str_replace(':limit', '$1', $query->sql());
        $statements = $this->storage->query('SELECT statement, parameter_types::text AS types FROM pg_prepared_statements');
        $matching = array_values(array_filter($statements, static fn(array $statement): bool => $statement['statement'] === $nativeSql));
        self::assertCount(1, $matching);
        self::assertSame($expectedType, $matching[0]['types']);

        $maximum = self::$fixture->query($name, ['limit' => PHP_INT_MAX]);
        if ($acceptsMaximum) {
            self::assertSame([3, 2, 1], array_column($this->executor->fetchAll($maximum), 'narrow'));
        } else {
            try {
                $this->executor->fetchAll($maximum);
                self::fail('A reused INTEGER parameter must retain its native range.');
            } catch (StorageException $exception) {
                self::assertSame('22003', $exception->getPrevious()?->getCode());
            }
        }

        self::assertSame([2, 1], array_column($this->executor->fetchAll($query), 'narrow'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function provideReusedLimitMatchesNativePostgreSqlTypeCases(): iterable
    {
        yield 'integer comparison' => ['ListNarrowBound', '{integer}', false];
        yield 'integer then bigint comparison' => ['ListNarrowFirst', '{integer}', false];
        yield 'bigint then integer comparison' => ['ListWideFirst', '{bigint}', true];
    }

    public function testOneMarkerDoesNotSilentlyLimitSql(): void
    {
        Fixture::seed($this->storage, 22);
        $bounded = self::$fixture->query('FindFirst', ['status' => 'published']);
        $unbounded = self::$fixture->query('FindUnbounded', ['status' => 'published']);
        self::assertInstanceOf(OneRowQuery::class, $bounded);
        self::assertInstanceOf(OneRowQuery::class, $unbounded);
        self::assertStringContainsString('LIMIT 1', $bounded->sql());
        self::assertStringNotContainsString('LIMIT', $unbounded->sql());
        self::assertCount(1, $this->executor->fetchAll($bounded));
        self::assertCount(22, $this->executor->fetchAll($unbounded));
        self::assertSame($this->executor->fetchOne($bounded), $this->executor->fetchOne($unbounded));
        self::assertEquals($this->executor->fetchOneRow($bounded), $this->executor->fetchOneRow($unbounded));
    }
}
