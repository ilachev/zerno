<?php

declare(strict_types=1);

namespace Tests\Integration\Storage;

use App\Platform\Storage\PostgreSQLStorage;
use App\Platform\Storage\StorageException;
use App\Platform\Storage\TransactionRollbackException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\PostgreSqlTestDatabase;

final class TransactionRecoveryTest extends TestCase
{
    private PostgreSqlTestDatabase $database;

    private PostgreSQLStorage $storage;

    protected function setUp(): void
    {
        $this->database = PostgreSqlTestDatabase::fromEnvironment();
        $this->database->create();
        $this->storage = $this->storage();
        $this->storage->execute('CREATE TABLE records (id BIGINT PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        $this->database->cleanup();
    }

    #[DataProvider('provideCaughtSqlFailureStillRollsBackTheWholeCallbackCases')]
    public function testCaughtSqlFailureStillRollsBackTheWholeCallback(string $method, string $state): void
    {
        $pid = $this->storage->queryOne('SELECT pg_backend_pid() AS pid');
        $caught = null;

        try {
            $this->storage->transaction(function () use ($method, &$caught): string {
                $this->storage->execute('INSERT INTO records VALUES (1)');

                try {
                    if ($method === 'lastInsertId') {
                        $this->storage->lastInsertId();
                    } else {
                        $this->storage->{$method}('SELECT 1 / 0');
                    }
                } catch (StorageException $failure) {
                    $caught = $failure;
                }

                return 'must not report success';
            });
            self::fail('A caught SQL failure must not turn an aborted transaction into success');
        } catch (StorageException $failure) {
            $cause = $failure->getPrevious();
            self::assertInstanceOf(\PDOException::class, $cause);
            self::assertSame($state, $cause->getCode());
            self::assertSame($caught, $failure);
        }
        self::assertSame([], $this->storage->query('SELECT id FROM records'));
        self::assertSame('committed', $this->storage->transaction(function (): string {
            $this->storage->execute('INSERT INTO records VALUES (2)');

            return 'committed';
        }));
        self::assertSame([['id' => 2]], $this->storage->query('SELECT id FROM records'));
        self::assertSame($pid, $this->storage->queryOne('SELECT pg_backend_pid() AS pid'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideCaughtSqlFailureStillRollsBackTheWholeCallbackCases(): iterable
    {
        yield 'execute' => ['execute', '22012'];
        yield 'query' => ['query', '22012'];
        yield 'queryOne' => ['queryOne', '22012'];
        yield 'lastInsertId' => ['lastInsertId', '55000'];
    }

    public function testDeferredCommitFailureKeepsItsSqlstateAndConnection(): void
    {
        $this->storage->execute('CREATE TABLE deferred_records (id BIGINT UNIQUE DEFERRABLE INITIALLY DEFERRED)');
        $pid = $this->storage->queryOne('SELECT pg_backend_pid() AS pid');

        try {
            $this->storage->transaction(fn(): bool => $this->storage->execute('INSERT INTO deferred_records VALUES (1), (1)'));
            self::fail('The deferred unique constraint must reject the commit');
        } catch (\PDOException $failure) {
            self::assertSame('23505', $failure->getCode());
        }
        self::assertSame([], $this->storage->query('SELECT id FROM deferred_records'));
        $this->storage->transaction(fn(): bool => $this->storage->execute('INSERT INTO deferred_records VALUES (2)'));
        self::assertSame([['id' => 2]], $this->storage->query('SELECT id FROM deferred_records'));
        self::assertSame($pid, $this->storage->queryOne('SELECT pg_backend_pid() AS pid'));
    }

    public function testNestedTransactionFailsBeforeItsCallbackAndRollsBackTheOuterOne(): void
    {
        try {
            $this->storage->transaction(function (): void {
                $this->storage->execute('INSERT INTO records VALUES (1)');
                $this->storage->transaction(fn(): bool => $this->storage->execute('INSERT INTO records VALUES (2)'));
            });
            self::fail('Nested transactions need an explicit caller policy');
        } catch (\LogicException $failure) {
            self::assertSame('Nested transactions are not supported', $failure->getMessage());
        }
        self::assertSame([], $this->storage->query('SELECT id FROM records'));
        self::assertTrue($this->storage->transaction(fn(): bool => $this->storage->execute('INSERT INTO records VALUES (3)')));
    }

    public function testCatchingANestedCallRejectionDoesNotPoisonTheOuterTransaction(): void
    {
        $this->storage->transaction(function (): void {
            $this->storage->execute('INSERT INTO records VALUES (1)');

            try {
                $this->storage->transaction(static fn(): int => 42);
                self::fail('The nested callback must not run');
            } catch (\LogicException) {
                $this->storage->execute('INSERT INTO records VALUES (2)');
            }
        });
        self::assertSame([['id' => 1], ['id' => 2]], $this->storage->query('SELECT id FROM records ORDER BY id'));
    }

    public function testAlreadyEndedTransactionDoesNotMaskTheCallbackException(): void
    {
        $failure = new \DomainException('Original callback failure');
        $propagated = null;

        try {
            $this->storage->transaction(function () use ($failure): void {
                $this->storage->execute('INSERT INTO records VALUES (1)');
                // Simulate a transaction already ended by its caller or server.
                $this->storage->execute('ROLLBACK');

                try {
                    $this->storage->transaction(static fn(): int => 42);
                    self::fail('The original callback still owns this transaction scope');
                } catch (\LogicException $nested) {
                    self::assertSame('Nested transactions are not supported', $nested->getMessage());
                }

                throw $failure;
            });
        } catch (\DomainException $caught) {
            $propagated = $caught;
        }
        self::assertSame($failure, $propagated);
        self::assertSame([], $this->storage->query('SELECT id FROM records'));
        self::assertTrue($this->storage->transaction(fn(): bool => $this->storage->execute('INSERT INTO records VALUES (2)')));
    }

    public function testRollbackFailurePreservesBothCausesAndRequiresANewStorage(): void
    {
        $failure = new \DomainException('Original callback failure');
        $admin = $this->database->connect();
        $propagated = null;

        try {
            $this->storage->transaction(function () use ($failure, $admin): void {
                $this->storage->execute('INSERT INTO records VALUES (1)');
                $row = $this->storage->queryOne('SELECT pg_backend_pid() AS pid');
                self::assertNotNull($row);
                $terminate = $admin->prepare('SELECT pg_terminate_backend(CAST(:pid AS INTEGER), 5000)');
                $terminate->execute(['pid' => $row['pid']]);
                self::assertTrue($terminate->fetchColumn());

                throw $failure;
            });
        } catch (TransactionRollbackException $caught) {
            $propagated = $caught;
        }
        self::assertInstanceOf(TransactionRollbackException::class, $propagated);
        self::assertSame($failure, $propagated->getPrevious());
        self::assertInstanceOf(\PDOException::class, $propagated->rollbackFailure);
        self::assertStringContainsString($failure->getMessage(), (string) $propagated);
        self::assertStringContainsString($propagated->rollbackFailure->getMessage(), (string) $propagated);

        try {
            $this->storage->queryOne('SELECT 1 AS id');
            self::fail('A terminated connection must not be silently replaced');
        } catch (StorageException $unavailable) {
            self::assertInstanceOf(\PDOException::class, $unavailable->getPrevious());
        }
        self::assertSame([], $this->storage()->query('SELECT id FROM records'));
    }

    private function storage(): PostgreSQLStorage
    {
        return new PostgreSQLStorage($this->database->host, $this->database->port, $this->database->database, $this->database->username, $this->database->password, $this->database->schema);
    }
}
