<?php

declare(strict_types=1);

namespace App\Platform\Storage\Sql;

use App\Platform\Storage\Storage;

final readonly class SqlExecutor
{
    public function __construct(
        private Storage $storage,
    ) {}

    /**
     * @template TParams of array<string, scalar|null>
     * @param ExecutableQuery<TParams> $query
     * @return array<string, scalar|null>|null
     * @phpstan-impure
     */
    public function fetchOne(ExecutableQuery $query): ?array
    {
        return $this->storage->queryOne($query->sql(), $query->params());
    }

    /**
     * @template T of DatabaseRow
     * @template TParams of array<string, scalar|null>
     * @param OneRowQuery<T, TParams> $query
     * @return T|null
     * @phpstan-impure
     */
    public function fetchOneRow(OneRowQuery $query): ?DatabaseRow
    {
        $row = $this->fetchOne($query);
        if ($row === null) {
            return null;
        }

        /** @var class-string<T> $rowClass */
        $rowClass = $query->rowClass();

        /** @var T */
        return $rowClass::fromDatabaseRow($row);
    }

    /**
     * @template TParams of array<string, scalar|null>
     * @param ExecutableQuery<TParams> $query
     * @return list<array<string, scalar|null>>
     * @phpstan-impure
     */
    public function fetchAll(ExecutableQuery $query): array
    {
        return $this->storage->query($query->sql(), $query->params());
    }

    /**
     * @template T of DatabaseRow
     * @template TParams of array<string, scalar|null>
     * @param ManyRowsQuery<T, TParams> $query
     * @return list<T>
     * @phpstan-impure
     */
    public function fetchAllRows(ManyRowsQuery $query): array
    {
        /** @var class-string<T> $rowClass */
        $rowClass = $query->rowClass();

        return array_map(
            /**
             * @return T
             */
            static fn(array $row): DatabaseRow => $rowClass::fromDatabaseRow($row),
            $this->fetchAll($query),
        );
    }

    /**
     * @template TParams of array<string, scalar|null>
     * @param ExecutableQuery<TParams> $query
     */
    public function execute(ExecutableQuery $query): bool
    {
        return $this->storage->execute($query->sql(), $query->params());
    }
}
