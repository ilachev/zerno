<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http\Endpoint;

use App\Generated\Endpoint\Api\V1\HealthService\CheckEndpoint;
use App\Platform\DI\Container;
use App\Platform\Http\Endpoint\EndpointImplementationResolver;
use App\Platform\Http\Endpoint\GeneratedEndpointImplementationMapProvider;
use App\Platform\Http\GeneratedOperationManifestProvider;
use PHPUnit\Framework\TestCase;

final class EndpointImplementationResolverTest extends TestCase
{
    public function testResolvesHandwrittenImplementationFromManifest(): void
    {
        $resolver = new EndpointImplementationResolver(
            new GeneratedEndpointImplementationMapProvider(
                new GeneratedOperationManifestProvider(\dirname(__DIR__, 5) . '/gen/Generated/OperationManifest'),
            ),
        );

        $implementation = $resolver->resolve(CheckEndpoint::class);

        self::assertSame(
            \App\Platform\Http\Endpoint\Api\V1\HealthService\CheckEndpoint::class,
            $implementation,
        );
    }

    public function testReturnsNullForNonGeneratedInterface(): void
    {
        $resolver = new EndpointImplementationResolver(
            new GeneratedEndpointImplementationMapProvider(
                new GeneratedOperationManifestProvider(\dirname(__DIR__, 5) . '/gen/Generated/OperationManifest'),
            ),
        );

        $implementation = $resolver->resolve(Container::class);

        self::assertNull($implementation);
    }
}
