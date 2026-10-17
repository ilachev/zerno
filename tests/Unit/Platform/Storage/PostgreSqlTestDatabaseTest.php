<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Storage;

use PHPUnit\Framework\TestCase;
use Tests\Integration\PostgreSqlTestDatabase;

final class PostgreSqlTestDatabaseTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['TEST_DB_NAME', 'TEST_DB_HOST', 'TEST_DB_PORT', 'TEST_DB_USER', 'TEST_DB_PASSWORD', 'DB_NAME', 'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testRequiresExplicitTestDatabaseBeforeConnecting(): void
    {
        putenv('DB_NAME=production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Set TEST_DB_NAME explicitly');

        PostgreSqlTestDatabase::fromEnvironment();
    }

    public function testNeverInheritsApplicationConnectionSettings(): void
    {
        putenv('TEST_DB_NAME=app_test');
        putenv('DB_HOST=production.example');
        putenv('DB_PORT=6543');
        putenv('DB_USER=production');
        putenv('DB_PASSWORD=production-secret');

        $database = PostgreSqlTestDatabase::fromEnvironment();

        self::assertSame('app_test', $database->database);
        self::assertSame('localhost', $database->host);
        self::assertSame(5432, $database->port);
        self::assertSame('app', $database->username);
        self::assertSame('password', $database->password);
    }

    public function testReadsDedicatedTestConnectionSettings(): void
    {
        putenv('TEST_DB_NAME=isolated_tests');
        putenv('TEST_DB_HOST=postgres-tests');
        putenv('TEST_DB_PORT=15432');
        putenv('TEST_DB_USER=tester');
        putenv('TEST_DB_PASSWORD=');

        $database = PostgreSqlTestDatabase::fromEnvironment();

        self::assertSame('isolated_tests', $database->database);
        self::assertSame('postgres-tests', $database->host);
        self::assertSame(15432, $database->port);
        self::assertSame('tester', $database->username);
        self::assertSame('', $database->password);
    }

    public function testRejectsInvalidTestPortBeforeConnecting(): void
    {
        putenv('TEST_DB_NAME=app_test');
        putenv('TEST_DB_PORT=invalid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TEST_DB_PORT');

        PostgreSqlTestDatabase::fromEnvironment();
    }

    public function testEveryRunOwnsADifferentSchema(): void
    {
        putenv('TEST_DB_NAME=app_test');

        $first = PostgreSqlTestDatabase::fromEnvironment();
        $second = PostgreSqlTestDatabase::fromEnvironment();

        self::assertMatchesRegularExpression('/\Aphpunit_[a-f0-9]{24}\z/', $first->schema);
        self::assertNotSame($first->schema, $second->schema);
        $first->cleanup();
        $second->cleanup();
    }

    public function testCannotConfigureApplicationBeforeCreatingSchema(): void
    {
        putenv('TEST_DB_NAME=app_test');
        $database = PostgreSqlTestDatabase::fromEnvironment();

        $this->expectException(\LogicException::class);
        $database->applicationEnvironment();
    }
}
