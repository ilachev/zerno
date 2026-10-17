<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\Routing\RouteDefinition;
use App\Platform\Routing\RouteDefinitionInterface;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\StaticRouteProvider;
use Zerno\Baseline\JsonHandler;
use Zerno\Baseline\SelectHandler;

require_once __DIR__ . '/Handler.php';

/** @var callable(Container<object>): void $configure */
$configure = require __DIR__ . '/../../config/core.php';

return static function (Container $container) use ($configure): void {
    $configure($container);
    $container->set(
        RouteDefinitionInterface::class,
        static fn(): RouteDefinition => new RouteDefinition(new StaticRouteProvider([
            new RouteEntry('GET', '/json', JsonHandler::class),
            new RouteEntry('GET', '/select/{id}', SelectHandler::class),
        ])),
    );
};
