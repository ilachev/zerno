<?php

declare(strict_types=1);

namespace App\Platform\DI\ServiceProviders;

use App\Platform\DataMapping\DataTransferObjectMapper;
use App\Platform\DI\Container;
use App\Platform\DI\ServiceProvider;
use App\Platform\Hydration\DefaultJsonFieldAdapter;
use App\Platform\Hydration\Hydrator;
use App\Platform\Hydration\JsonFieldAdapter;

/**
 * @implements ServiceProvider<object>
 */
final readonly class PlatformSupportServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        // JSON field adapter
        $container->bind(JsonFieldAdapter::class, DefaultJsonFieldAdapter::class);
        $container->set(
            DefaultJsonFieldAdapter::class,
            static function (Container $container): DefaultJsonFieldAdapter {
                /** @var Hydrator $hydrator */
                $hydrator = $container->get(Hydrator::class);

                return new DefaultJsonFieldAdapter($hydrator);
            },
        );

        // DataTransferObjectMapper
        $container->set(
            DataTransferObjectMapper::class,
            static function (Container $container): DataTransferObjectMapper {
                /** @var Hydrator $hydrator */
                $hydrator = $container->get(Hydrator::class);

                return new DataTransferObjectMapper($hydrator);
            },
        );

    }
}
