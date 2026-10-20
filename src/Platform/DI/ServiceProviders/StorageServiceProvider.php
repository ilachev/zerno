<?php

declare(strict_types=1);

namespace App\Platform\DI\ServiceProviders;

use App\Platform\Config\ProjectPath;
use App\Platform\DI\Container;
use App\Platform\DI\ServiceProvider;
use App\Platform\Storage\PostgreSQLStorage;
use App\Platform\Storage\PostgreSqlStorageConfig;
use App\Platform\Storage\Sql\SqlExecutor;
use App\Platform\Storage\Storage;

/** @implements ServiceProvider<object> */
final readonly class StorageServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(PostgreSqlStorageConfig::class, static function (): PostgreSqlStorageConfig {
            /** @var PostgreSqlStorageConfig $config */
            $config = require ProjectPath::getConfigPath('storage.php');

            return $config;
        });
        $container->set(Storage::class, static function (Container $container): Storage {
            $config = $container->get(PostgreSqlStorageConfig::class);

            return new PostgreSQLStorage(
                host: $config->host,
                port: $config->port,
                dbname: $config->database,
                username: $config->username,
                password: $config->password,
                schema: $config->schema,
            );
        });
        $container->set(SqlExecutor::class, static fn(Container $container): SqlExecutor => new SqlExecutor($container->get(Storage::class)));
    }
}
