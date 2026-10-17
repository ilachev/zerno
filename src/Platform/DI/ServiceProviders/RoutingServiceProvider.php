<?php

declare(strict_types=1);

namespace App\Platform\DI\ServiceProviders;

use App\Platform\DI\Container;
use App\Platform\DI\ServiceProvider;
use App\Platform\Http\Endpoint\GeneratedEndpointImplementationMapProvider;
use App\Platform\Http\GeneratedOperationManifestProvider;
use App\Platform\Routing\Generator\GeneratedOperationRouteProvider;
use App\Platform\Routing\RouteDefinition;
use App\Platform\Routing\RouteDefinitionInterface;
use App\Platform\Routing\Router;
use App\Platform\Routing\RouterInterface;

/**
 * @implements ServiceProvider<object>
 */
final readonly class RoutingServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        // Router
        $container->bind(RouterInterface::class, Router::class);

        if (!$container->has(GeneratedOperationManifestProvider::class)) {
            $container->set(
                GeneratedOperationManifestProvider::class,
                static fn() => new GeneratedOperationManifestProvider(
                    \dirname(__DIR__, 4) . '/gen/Generated/OperationManifest',
                ),
            );
        }

        $container->set(
            GeneratedEndpointImplementationMapProvider::class,
            static fn(Container $container): GeneratedEndpointImplementationMapProvider => new GeneratedEndpointImplementationMapProvider(
                $container->get(GeneratedOperationManifestProvider::class),
            ),
        );

        foreach ($container->get(GeneratedEndpointImplementationMapProvider::class)->getImplementations() as $interface => $implementation) {
            $container->bind($interface, $implementation);
        }
        $container->set(
            GeneratedOperationRouteProvider::class,
            static fn(Container $container) => new GeneratedOperationRouteProvider(
                $container->get(GeneratedOperationManifestProvider::class),
            ),
        );

        // Route definition
        $container->set(
            RouteDefinitionInterface::class,
            static fn(Container $container) => new RouteDefinition(
                $container->get(GeneratedOperationRouteProvider::class),
            ),
        );
    }
}
