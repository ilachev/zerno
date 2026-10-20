<?php

declare(strict_types=1);

use App\Platform\Storage\PostgreSqlStorageConfig;

return new PostgreSqlStorageConfig(
    host: getenv('DB_HOST') ?: 'localhost',
    port: (int) (getenv('DB_PORT') ?: 5432),
    database: getenv('DB_NAME') ?: 'app',
    username: getenv('DB_USER') ?: 'app',
    password: getenv('DB_PASSWORD') ?: 'password',
    schema: getenv('DB_SCHEMA') ?: 'public',
);
