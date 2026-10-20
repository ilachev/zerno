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
use Tests\Unit\Platform\Logging\TestLogger;

final class MigrationServiceTest extends TestCase
{
    private const string TEST_DB = ':memory:';

    private SQLiteStorage $storage;

    private MigrationService $service;

    protected function setUp(): void
    {
        $this->storage = new SQLiteStorage(self::TEST_DB);

        // Create migrations table
        $this->storage->execute(<<<'SQL'
                CREATE TABLE IF NOT EXISTS migrations (
                    version TEXT PRIMARY KEY,
                    executed_at INTEGER NOT NULL
                )
            SQL);

        $repository = new MigrationRepository($this->storage);
        // Create a loader that doesn't load any migrations from the filesystem
        $loader = new MigrationLoader('', new TestLogger());
        $this->service = new MigrationService($this->storage, $repository, $loader);
    }

    public function testMigrateExecutesNewMigrationsInOrder(): void
    {
        // Arrange
        $migration1 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return 'CREATE TABLE test1 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test1;';
            }
        };

        $migration2 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317002';
            }

            public function up(): string
            {
                return 'CREATE TABLE test2 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test2;';
            }
        };

        // Act
        $this->service->addMigration($migration2);
        $this->service->addMigration($migration1);
        $this->service->migrate();

        // Assert
        $tables = $this->storage->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('test1', 'test2')");
        $tableNames = array_column($tables, 'name');
        sort($tableNames);

        self::assertEquals(['test1', 'test2'], $tableNames);

        $migrations = $this->storage->query('SELECT version FROM migrations ORDER BY version');
        $versions = array_column($migrations, 'version');

        self::assertEquals(['20240317001', '20240317002'], $versions);
    }

    public function testMigrateHandlesMultipleStatements(): void
    {
        // Arrange
        $migration = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return <<<'SQL'
                        CREATE TABLE test1 (
                            id INTEGER PRIMARY KEY,
                            name TEXT NOT NULL
                        );
                        CREATE INDEX idx_test1_name ON test1(name);
                        CREATE TABLE test2 (id INTEGER PRIMARY KEY);
                    SQL;
            }

            public function down(): string
            {
                return <<<'SQL'
                        DROP TABLE test2;
                        DROP TABLE test1;
                    SQL;
            }
        };

        // Act
        $this->service->addMigration($migration);
        $this->service->migrate();

        // Assert
        $tables = $this->storage->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('test1', 'test2')");
        $tableNames = array_column($tables, 'name');
        sort($tableNames);

        self::assertEquals(['test1', 'test2'], $tableNames);

        $indexes = $this->storage->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='test1'");
        $indexNames = array_column($indexes, 'name');

        self::assertContains('idx_test1_name', $indexNames);
    }

    public function testRollbackExecutesMigrationsInReverseOrder(): void
    {
        // Arrange - execute migrations first
        $migration1 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return 'CREATE TABLE test1 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test1;';
            }
        };

        $migration2 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317002';
            }

            public function up(): string
            {
                return 'CREATE TABLE test2 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test2;';
            }
        };

        $this->service->addMigration($migration1);
        $this->service->addMigration($migration2);
        $this->service->migrate();

        // Act - perform rollback
        $this->service->rollback();

        // Assert
        $tables = $this->storage->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('test1', 'test2')");

        self::assertEmpty($tables);

        $migrations = $this->storage->query('SELECT version FROM migrations');

        self::assertEmpty($migrations);
    }

    public function testMigrateSkipsAlreadyExecutedMigrations(): void
    {
        // Arrange
        $migration1 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return 'CREATE TABLE test1 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test1;';
            }
        };

        $migration2 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317002';
            }

            public function up(): string
            {
                return 'CREATE TABLE test2 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test2;';
            }
        };

        // Add both migrations before execution
        $this->service->addMigration($migration1);
        $this->service->addMigration($migration2);

        // Execute first round of migrations
        $this->service->migrate();

        // Act - try to execute migrations again
        $this->service->migrate();

        // Assert
        $tables = $this->storage->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('test1', 'test2')");
        $tableNames = array_column($tables, 'name');
        sort($tableNames);

        self::assertEquals(['test1', 'test2'], $tableNames);

        $migrations = $this->storage->query('SELECT version FROM migrations ORDER BY version');
        $versions = array_column($migrations, 'version');

        self::assertEquals(['20240317001', '20240317002'], $versions);
    }

    public function testMigrateHandlesInvalidSql(): void
    {
        // Arrange
        $migration = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return 'INVALID SQL QUERY;';
            }

            public function down(): string
            {
                return 'DROP TABLE IF EXISTS test;';
            }
        };

        $this->service->addMigration($migration);

        // Assert
        $this->expectException(StorageException::class);

        // Act
        $this->service->migrate();
    }

    public function testMigrateHandlesEmptyMigration(): void
    {
        // Arrange
        $migration = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return '';
            }

            public function down(): string
            {
                return '';
            }
        };

        $this->service->addMigration($migration);

        // Act
        $this->service->migrate();

        // Assert
        $migrations = $this->storage->query('SELECT version FROM migrations');
        $versions = array_column($migrations, 'version');

        self::assertEquals(['20240317001'], $versions);
    }

    public function testMigrateHandlesVersionConflict(): void
    {
        // Arrange
        $migration1 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return 'CREATE TABLE test1 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test1;';
            }
        };

        $migration2 = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return 'CREATE TABLE test2 (id INTEGER PRIMARY KEY);';
            }

            public function down(): string
            {
                return 'DROP TABLE test2;';
            }
        };

        $this->service->addMigration($migration1);
        $this->service->addMigration($migration2);

        // Act
        $this->service->migrate();

        // Assert
        $tables = $this->storage->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('test1', 'test2')");
        $tableNames = array_column($tables, 'name');

        // Only the first migration with this version should be executed
        self::assertEquals(['test1'], $tableNames);

        $migrations = $this->storage->query('SELECT version FROM migrations');
        $versions = array_column($migrations, 'version');

        self::assertEquals(['20240317001'], $versions);
    }

    public function testMultiStatementMigration(): void
    {
        // Arrange
        $migration = new class implements Migration {
            public function getVersion(): string
            {
                return '20240317001';
            }

            public function up(): string
            {
                return <<<'SQL'
                        CREATE TABLE sessions (
                            id TEXT PRIMARY KEY,
                            user_id INTEGER NOT NULL,
                            payload TEXT NOT NULL,
                            expires_at INTEGER NOT NULL,
                            created_at INTEGER NOT NULL,
                            updated_at INTEGER NOT NULL
                        );
                        CREATE INDEX idx_sessions_user_id ON sessions(user_id);
                        CREATE INDEX idx_sessions_expires_at ON sessions(expires_at);
                    SQL;
            }

            public function down(): string
            {
                return 'DROP TABLE IF EXISTS sessions;';
            }
        };

        // Act
        $this->service->addMigration($migration);
        $this->service->migrate();

        // Assert
        $tables = $this->storage->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'",
        );
        self::assertCount(1, $tables, 'Table sessions should be created');

        $indexes = $this->storage->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='sessions'",
        );
        $indexNames = array_column($indexes, 'name');
        sort($indexNames);

        self::assertEquals(
            ['idx_sessions_expires_at', 'idx_sessions_user_id', 'sqlite_autoindex_sessions_1'],
            $indexNames,
            'Both indexes should be created',
        );

        // Test column definitions
        $columns = $this->storage->query('PRAGMA table_info(sessions)');
        $columnInfo = [];
        foreach ($columns as $column) {
            $name = $column['name'] ?? null;
            self::assertIsString($name);

            $columnInfo[$name] = [
                'type' => $column['type'],
                'notnull' => (bool) $column['notnull'],
                'pk' => (bool) $column['pk'],
            ];
        }

        self::assertEquals(
            [
                'type' => 'TEXT',
                'notnull' => false,
                'pk' => true,
            ],
            $columnInfo['id'],
            'ID column should be TEXT PRIMARY KEY',
        );

        self::assertEquals(
            [
                'type' => 'INTEGER',
                'notnull' => true,
                'pk' => false,
            ],
            $columnInfo['user_id'],
            'user_id column should be INTEGER NOT NULL',
        );

        // Test rollback
        $this->service->rollback();

        $tablesAfterRollback = $this->storage->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'",
        );
        self::assertEmpty($tablesAfterRollback, 'Table should be dropped after rollback');
    }
}
