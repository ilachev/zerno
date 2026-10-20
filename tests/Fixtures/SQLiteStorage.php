<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Platform\Storage\AbstractStorage;
use App\Platform\Storage\StorageException;

final class SQLiteStorage extends AbstractStorage
{
    private \PDO $connection;

    /** @var array<string, \PDOStatement> */
    private array $preparedStatements = [];

    public function __construct(string $path)
    {
        $this->connection = new \PDO("sqlite:{$path}");

        $this->connection->exec('PRAGMA foreign_keys = ON');

        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->connection->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->connection->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<array<string, scalar|null>>
     * @throws StorageException
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $statement = $this->getStatement($sql);
            $statement->execute($params);
            /** @var array<array-key, array<string, scalar|null>> $result */
            $result = $statement->fetchAll();

            return array_values($result);
        } catch (\PDOException $e) {
            throw new StorageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, scalar|null> $params
     * @throws StorageException
     */
    public function execute(string $sql, array $params = []): bool
    {
        try {
            $statement = $this->getStatement($sql);

            return $statement->execute($params);
        } catch (\PDOException $e) {
            throw new StorageException($e->getMessage(), 0, $e);
        }
    }

    protected function connection(): \PDO
    {
        return $this->connection;
    }

    private function getStatement(string $sql): \PDOStatement
    {
        $key = md5($sql);

        if (!isset($this->preparedStatements[$key])) {
            $this->preparedStatements[$key] = $this->connection->prepare($sql);

            if (\count($this->preparedStatements) > 100) {
                array_shift($this->preparedStatements);
            }
        }

        return $this->preparedStatements[$key];
    }
}
