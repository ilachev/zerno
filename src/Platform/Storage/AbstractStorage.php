<?php

declare(strict_types=1);

namespace App\Platform\Storage;

abstract class AbstractStorage implements Storage
{
    private bool $ownsTransaction = false;

    private ?StorageException $transactionFailure = null;

    abstract protected function connection(): \PDO;

    /**
     * @throws \Throwable
     */
    final public function transaction(callable $callback): mixed
    {
        $connection = $this->connection();
        if ($this->ownsTransaction || $connection->inTransaction()) {
            throw new \LogicException('Nested transactions are not supported');
        }
        $connection->beginTransaction();
        $this->ownsTransaction = true;

        try {
            $result = $callback();
            // PostgreSQL can acknowledge COMMIT as ROLLBACK after a caught SQL error.
            if ($this->transactionFailure !== null) {
                throw $this->transactionFailure;
            }
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBackAfterFailure($connection, $e);

            throw $e;
        } finally {
            $this->ownsTransaction = false;
            $this->transactionFailure = null;
        }
    }

    private function rollBackAfterFailure(\PDO $connection, \Throwable $failure): void
    {
        try {
            // A failed commit may already have ended the transaction.
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (\Throwable $rollbackFailure) {
            throw new TransactionRollbackException($failure, $rollbackFailure);
        }
    }

    protected function recordFailure(\PDOException $cause): StorageException
    {
        $failure = new StorageException($cause->getMessage(), 0, $cause);
        if ($this->ownsTransaction && $this->transactionFailure === null) {
            $this->transactionFailure = $failure;
        }

        return $failure;
    }

    /**
     * @throws StorageException
     */
    final public function lastInsertId(): string
    {
        try {
            $id = $this->connection()->lastInsertId();
        } catch (\PDOException $cause) {
            throw $this->recordFailure($cause);
        }

        if ($id === false) {
            throw new StorageException('Failed to get last insert ID');
        }

        return $id;
    }
}
