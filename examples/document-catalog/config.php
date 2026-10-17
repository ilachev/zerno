<?php

declare(strict_types=1);

use App\Platform\Config\ProjectPath;
use App\Platform\DI\Container;
use App\Platform\DI\ServiceProviders\MigrationServiceProvider;
use App\Platform\Http\Middleware\Pipeline;
use App\Platform\Http\Middleware\RequestContextMiddleware;
use App\Platform\Http\Middleware\RoutingMiddleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\RouteHandlerResolver;
use App\Platform\Logging\Logger;
use App\Platform\Routing\RouteDefinition;
use App\Platform\Routing\RouteDefinitionInterface;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\StaticRouteProvider;
use Examples\DocumentCatalog\Errors;
use Examples\DocumentCatalog\FileStore;

require_once __DIR__ . '/bootstrap.php';

/** @var Closure(Container<object>): void $core */
$core = require __DIR__ . '/../../config/core.php';

return static function (Container $container) use ($core): void {
    $core($container);
    (new MigrationServiceProvider(__DIR__ . '/migrations'))->register($container);
    $container->set(
        RouteDefinitionInterface::class,
        static function (): RouteDefinition {
            /** @var list<RouteEntry> $routes */
            $routes = require __DIR__ . '/routes.php';

            return new RouteDefinition(new StaticRouteProvider($routes));
        },
    );
    $container->set(
        FileStore::class,
        static fn(Container $container): FileStore => new FileStore(
            getenv('CATALOG_FILES_DIR') ?: ProjectPath::getBasePath() . '/var/document-catalog/files',
            $container->get(Logger::class),
        ),
    );
    $container->set(
        RequestHandler::class,
        static fn(Container $container): RequestHandler => new Pipeline(
            $container->get(RouteHandlerResolver::class),
            [
                $container->get(RequestContextMiddleware::class),
                $container->get(Errors::class),
                $container->get(RoutingMiddleware::class),
            ],
        ),
    );
};
