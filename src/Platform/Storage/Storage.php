<?php

declare(strict_types=1);

namespace App\Platform\Storage;

interface Storage
{
    /**
     * @param array<string, scalar|null> $params
     * @return list<array<string, scalar|null>>
     * @phpstan-impure
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Returns the first row or null, without rewriting SQL or checking uniqueness.
     * Closes the PDO cursor before returning; native buffers follow the driver's statement lifetime.
     * @param array<string, scalar|null> $params
     * @return array<string, scalar|null>|null
     * @phpstan-impure
     */
    public function queryOne(string $sql, array $params = []): ?array;

    /**
     * @param array<string, scalar|null> $params
     */
    public function execute(string $sql, array $params = []): bool;

    /**
     * Owns one transaction; nested calls are rejected. A caught SQL failure still aborts it.
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \Throwable
     * @phpstan-impure
     */
    public function transaction(callable $callback): mixed;

    public function lastInsertId(): string;
}
