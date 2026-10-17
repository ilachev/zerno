<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\DI\ServiceProviders\CoreServiceProvider;
use App\Platform\DI\ServiceProviders\MigrationServiceProvider;
use App\Platform\DI\ServiceProviders\StorageServiceProvider;
use App\Platform\Http\Middleware\ErrorHandlerMiddleware;
use App\Platform\Http\Middleware\Pipeline;
use App\Platform\Http\Middleware\RequestContextMiddleware;
use App\Platform\Http\Middleware\RoutingMiddleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\RouteHandlerResolver;
use App\Platform\Routing\RouteDefinition;
use App\Platform\Routing\RouteDefinitionInterface;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\Router;
use App\Platform\Routing\RouterInterface;
use App\Platform\Routing\StaticRouteProvider;

return static function (Container $container): void {
    foreach ([
        new CoreServiceProvider(),
        new StorageServiceProvider(),
        new MigrationServiceProvider(__DIR__ . '/../src/Migrations'),
    ] as $provider) {
        $provider->register($container);
    }

    $container->bind(RouterInterface::class, Router::class);
    $container->set(
        RouteDefinitionInterface::class,
        static function (): RouteDefinition {
            /** @var list<RouteEntry> $routes */
            $routes = require __DIR__ . '/routes.php';

            return new RouteDefinition(new StaticRouteProvider($routes));
        },
    );
    $container->set(
        RequestHandler::class,
        static fn(Container $container): RequestHandler => new Pipeline(
            $container->get(RouteHandlerResolver::class),
            [
                $container->get(RequestContextMiddleware::class),
                $container->get(ErrorHandlerMiddleware::class),
                $container->get(RoutingMiddleware::class),
            ],
        ),
    );
};
