<?php

declare(strict_types=1);

namespace App\Platform\DI\ServiceProviders;

use App\Platform\DI\Container;
use App\Platform\DI\ServiceProvider;
use App\Platform\Logging\Logger;
use App\Platform\Storage\Migration\MigrationLoader;
use App\Platform\Storage\Migration\MigrationRepository;
use App\Platform\Storage\Migration\MigrationService;
use App\Platform\Storage\Storage;
use App\Platform\Storage\StorageConfig;

/**
 * @implements ServiceProvider<object>
 */
final readonly class MigrationServiceProvider implements ServiceProvider
{
    public function __construct(private ?string $migrationsPath = null) {}

    public function register(Container $container): void
    {
        $migrationsPath = $this->migrationsPath;
        // Migration loader
        $container->set(
            MigrationLoader::class,
            static function (Container $container) use ($migrationsPath): MigrationLoader {
                /** @var StorageConfig $storageConfig */
                $storageConfig = $container->get(StorageConfig::class);

                /** @var Logger $logger */
                $logger = $container->get(Logger::class);

                return new MigrationLoader($migrationsPath ?? $storageConfig->getMigrationsPath(), $logger);
            },
        );

        // Migration repository
        $container->set(
            MigrationRepository::class,
            static function (Container $container): MigrationRepository {
                /** @var Storage $storage */
                $storage = $container->get(Storage::class);

                return new MigrationRepository($storage);
            },
        );

        // Migration service
        $container->set(
            MigrationService::class,
            static function (Container $container): MigrationService {
                /** @var Storage $storage */
                $storage = $container->get(Storage::class);

                /** @var MigrationRepository $repository */
                $repository = $container->get(MigrationRepository::class);

                /** @var MigrationLoader $loader */
                $loader = $container->get(MigrationLoader::class);

                return new MigrationService($storage, $repository, $loader);
            },
        );
    }
}
