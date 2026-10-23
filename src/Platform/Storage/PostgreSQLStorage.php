<?php

declare(strict_types=1);

namespace App\Platform\Storage;

final class PostgreSQLStorage extends AbstractStorage
{
    private ?\PDO $connection = null;

    /** @var array<string, \PDOStatement> */
    private array $preparedStatements = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $dbname,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $schema = null,
    ) {}

    protected function connection(): \PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}";

        $connection = new \PDO(
            $dsn,
            $this->username,
            $this->password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        // Publish the connection only after schema initialization succeeds.
        if ($this->schema !== null) {
            $connection->exec('SET search_path TO "' . str_replace('"', '""', $this->schema) . '"');
        }

        return $this->connection = $connection;
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
     * @return array<string, scalar|null>|null
     * @throws StorageException
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        try {
            $statement = $this->getStatement($sql);

            try {
                $statement->execute($params);
                /** @var array<string, scalar|null>|false $row */
                $row = $statement->fetch(\PDO::FETCH_ASSOC);

                return $row === false ? null : $row;
            } finally {
                $statement->closeCursor();
            }
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

    /**
     * Gets or creates a prepared statement for better performance.
     *
     * @throws \PDOException
     */
    private function getStatement(string $sql): \PDOStatement
    {
        // Use a hash of the SQL as cache key
        $key = md5($sql);

        if (!isset($this->preparedStatements[$key])) {
            // Create a new statement if not cached
            $this->preparedStatements[$key] = $this->connection()->prepare($sql);

            // Limit cache size to prevent memory leaks
            if (\count($this->preparedStatements) > 100) {
                // Remove the oldest element when cache is full
                array_shift($this->preparedStatements);
            }
        }

        return $this->preparedStatements[$key];
    }
}
