<?php

declare(strict_types=1);

namespace App\Platform\Storage;

final readonly class PostgreSqlStorageConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $schema,
    ) {}
}
