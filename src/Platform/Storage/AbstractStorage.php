<?php

declare(strict_types=1);

namespace App\Platform\Storage;

abstract class AbstractStorage implements Storage
{
    abstract protected function connection(): \PDO;

    /**
     * @throws \Throwable
     */
    final public function transaction(callable $callback): mixed
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $result = $callback();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * @throws StorageException
     */
    final public function lastInsertId(): string
    {
        $id = $this->connection()->lastInsertId();

        if ($id === false) {
            throw new StorageException('Failed to get last insert ID');
        }

        return $id;
    }
}
