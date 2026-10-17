<?php

declare(strict_types=1);

use App\Capabilities\ApiStats\ApiStatsModule;
use App\Capabilities\ApiStats\Transport\Http\ApiStatsMiddleware;
use App\Capabilities\Capability;
use App\Capabilities\Session\SessionModule;
use App\Capabilities\Session\Transport\Http\SessionMiddleware;
use App\Platform\DI\Container;
use App\Platform\DI\ServiceProviders\CacheServiceProvider;
use App\Platform\DI\ServiceProviders\CoreServiceProvider;
use App\Platform\DI\ServiceProviders\HttpClientServiceProvider;
use App\Platform\DI\ServiceProviders\HydratorServiceProvider;
use App\Platform\DI\ServiceProviders\MigrationServiceProvider;
use App\Platform\DI\ServiceProviders\PlatformSupportServiceProvider;
use App\Platform\DI\ServiceProviders\RoadRunnerServiceProvider;
use App\Platform\DI\ServiceProviders\RoutingServiceProvider;
use App\Platform\DI\ServiceProviders\StorageServiceProvider;
use App\Platform\Http\Middleware\ErrorHandlerMiddleware;
use App\Platform\Http\Middleware\HttpLoggingMiddleware;
use App\Platform\Http\Middleware\Pipeline;
use App\Platform\Http\Middleware\RequestContextMiddleware;
use App\Platform\Http\Middleware\RoutingMiddleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\RouteHandlerResolver;

return static function (Container $container): void {
    $platformProviders = [
        new CoreServiceProvider(),
        new RoadRunnerServiceProvider(),
        new CacheServiceProvider(),
        new StorageServiceProvider(),
        new MigrationServiceProvider(),
        new HttpClientServiceProvider(),
        new RoutingServiceProvider(),
        new PlatformSupportServiceProvider(),
        new HydratorServiceProvider(),
    ];

    /** @var list<Capability<object>> $capabilities */
    $capabilities = [
        new ApiStatsModule(),
        new SessionModule(),
    ];

    foreach ($platformProviders as $provider) {
        $provider->register($container);
    }

    foreach ($capabilities as $capability) {
        $capability->register($container);
    }

    $container->set(
        RequestHandler::class,
        static fn(Container $container): RequestHandler => new Pipeline(
            $container->get(RouteHandlerResolver::class),
            [
                $container->get(RequestContextMiddleware::class),
                $container->get(ErrorHandlerMiddleware::class),
                $container->get(SessionMiddleware::class),
                $container->get(ApiStatsMiddleware::class),
                $container->get(RoutingMiddleware::class),
                $container->get(HttpLoggingMiddleware::class),
            ],
        ),
    );
};
