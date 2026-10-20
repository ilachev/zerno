<?php

declare(strict_types=1);

namespace App\Platform\DI\ServiceProviders;

use App\Platform\DI\Container;
use App\Platform\DI\ContainerHandlerFactory;
use App\Platform\DI\ServiceProvider;
use App\Platform\Http\ErrorResponse;
use App\Platform\Http\Handler\HandlerFactoryInterface;
use App\Platform\Http\JsonResponse;
use App\Platform\Logging\Logger;
use App\Platform\Logging\ReadableOutputLogger;
use App\Platform\Runtime\RandomRequestIdGenerator;
use App\Platform\Runtime\RequestContextFactory;
use App\Platform\Runtime\RequestIdGenerator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

/**
 * @implements ServiceProvider<object>
 */
final readonly class CoreServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(Container::class, static fn(): Container => $container);

        // PSR-7 factories
        $container->bind(ServerRequestFactoryInterface::class, Psr17Factory::class);
        $container->bind(StreamFactoryInterface::class, Psr17Factory::class);
        $container->bind(UploadedFileFactoryInterface::class, Psr17Factory::class);

        // Logger
        $container->bind(ReadableOutputLogger::class, ReadableOutputLogger::class);
        $container->bind(Logger::class, ReadableOutputLogger::class);
        $container->bind(RequestIdGenerator::class, RandomRequestIdGenerator::class);
        $container->bind(RequestContextFactory::class, RequestContextFactory::class);
        $container->bind(JsonResponse::class, JsonResponse::class);
        $container->bind(ErrorResponse::class, JsonResponse::class);

        // Handler factory
        $container->bind(HandlerFactoryInterface::class, ContainerHandlerFactory::class);
        $container->set(
            ContainerHandlerFactory::class,
            static fn(Container $container): ContainerHandlerFactory => new ContainerHandlerFactory($container),
        );
    }
}
