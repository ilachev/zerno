<?php

declare(strict_types=1);

use App\Platform\Http\JsonResponse;
use App\Platform\Http\Middleware\ErrorHandlerMiddleware;
use App\Platform\Http\Middleware\Pipeline;
use App\Platform\Http\Middleware\RequestContextMiddleware;
use App\Platform\Http\Middleware\RoutingMiddleware;
use App\Platform\Http\RouteHandlerResolver;
use App\Platform\Logging\Logger;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\Router;
use App\Platform\Runtime\App;
use App\Platform\Runtime\RandomRequestIdGenerator;
use App\Platform\Runtime\RequestContextFactory;

return static function (Logger $logger): App {
    $errors = new JsonResponse();
    /** @var list<RouteEntry> $routes */
    $routes = require __DIR__ . '/routes.php';

    return new App(new Pipeline(
        new RouteHandlerResolver(),
        [
            new RequestContextMiddleware($logger, new RequestContextFactory(new RandomRequestIdGenerator())),
            new ErrorHandlerMiddleware($errors, $logger),
            new RoutingMiddleware(new Router($routes), $errors),
        ],
    ));
};
