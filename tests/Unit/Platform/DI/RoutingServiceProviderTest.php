<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DI;

use App\Generated\Endpoint\Api\V1\HealthService\CheckEndpoint;
use App\Generated\Endpoint\Api\V1\HealthService\CheckHttpHandler;
use App\Generated\Endpoint\Api\V1\SystemService\DescribeEndpoint;
use App\Platform\DI\DIContainer;
use App\Platform\DI\ServiceProviders\RoutingServiceProvider;
use App\Platform\Http\GeneratedOperationManifestProvider;
use App\Platform\Routing\RouterInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class RoutingServiceProviderTest extends TestCase
{
    public function testRegistersGeneratedEndpointsAndRoutesForTheApi(): void
    {
        $container = new DIContainer();
        (new RoutingServiceProvider())->register($container);

        self::assertTrue($container->has(CheckEndpoint::class));
        self::assertTrue($container->has(DescribeEndpoint::class));
        self::assertInstanceOf(
            \App\Platform\Http\Endpoint\Api\V1\HealthService\CheckEndpoint::class,
            $container->get(CheckEndpoint::class),
        );
        self::assertInstanceOf(
            \App\Platform\Http\Endpoint\Api\V1\SystemService\DescribeEndpoint::class,
            $container->get(DescribeEndpoint::class),
        );

        $route = $container->get(RouterInterface::class)->dispatch(new ServerRequest('GET', '/api/v1/health'));

        self::assertTrue($route->isFound());
        self::assertSame(CheckHttpHandler::class, $route->getHandler());
    }

    public function testUsesTheConfiguredManifestForBothRoutesAndEndpointBindings(): void
    {
        $container = new DIContainer();
        $manifest = new GeneratedOperationManifestProvider(__DIR__ . '/Fixtures/no-generated-manifests');
        $container->set(GeneratedOperationManifestProvider::class, static fn(): GeneratedOperationManifestProvider => $manifest);

        (new RoutingServiceProvider())->register($container);

        self::assertSame($manifest, $container->get(GeneratedOperationManifestProvider::class));
        self::assertFalse($container->has(CheckEndpoint::class));
        self::assertFalse($container->has(DescribeEndpoint::class));
        self::assertFalse(
            $container->get(RouterInterface::class)->dispatch(new ServerRequest('GET', '/api/v1/health'))->isFound(),
        );
    }
}
