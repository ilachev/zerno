<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\Http\ErrorResponse;
use App\Platform\Routing\RouteDefinition;
use App\Platform\Routing\RouteDefinitionInterface;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\StaticRouteProvider;
use Examples\DocumentCatalog\Errors;
use Project\HttpFixtures\FixtureHandler;

require_once __DIR__ . '/FixtureHandler.php';

/** @var callable(Container<object>): void $configure */
$configure = require dirname(__DIR__, 2) . '/config/core.php';

return static function (Container $container) use ($configure): void {
    $configure($container);
    if (getenv('WIRE_ERROR_FORMAT') === 'html') {
        require_once dirname(__DIR__, 2) . '/examples/document-catalog/bootstrap.php';
        $container->bind(ErrorResponse::class, Errors::class);
    }
    $container->set(
        RouteDefinitionInterface::class,
        static fn(): RouteDefinition => new RouteDefinition(new StaticRouteProvider([
            new RouteEntry('GET', '/__wire/static', FixtureHandler::class),
            new RouteEntry('HEAD', '/__wire/static', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/items/{id}', FixtureHandler::class),
            new RouteEntry('DELETE', '/__wire/items/{id}', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/items/current', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/files/{name}.json', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/a+b/{id}', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/status/204', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/status/304', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/headers', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/failure', FixtureHandler::class),
            new RouteEntry('HEAD', '/__wire/failure', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/context/{id}', FixtureHandler::class),
            new RouteEntry('GET', '/__wire/context', FixtureHandler::class),
        ])),
    );
};
