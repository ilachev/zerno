<?php

declare(strict_types=1);

return [
    // PostgreSQL is the primary runtime adapter for real backends.
    'engine' => getenv('STORAGE_ENGINE') ?: 'pgsql',

    'sqlite' => [
        'database' => getenv('SQLITE_DATABASE') ?: __DIR__ . '/../var/app.sqlite',
        'migrations_path' => __DIR__ . '/../src/Platform/Storage/Migration/SQLite',
    ],

    'pgsql' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('DB_PORT') ?: 5432),
        'database' => getenv('DB_NAME') ?: 'app',
        'username' => getenv('DB_USER') ?: 'app',
        'password' => getenv('DB_PASSWORD') ?: 'password',
        'schema' => getenv('DB_SCHEMA') ?: 'public',
        'migrations_path' => __DIR__ . '/../src/Platform/Storage/Migration/PostgreSQL',
    ],
];
