<?php

declare(strict_types=1);

use App\Platform\Http\Handler\HealthHandler;
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
use Examples\JsonEcho\EchoHandler;

require_once __DIR__ . '/EchoHandler.php';

return static function (Logger $logger): App {
    $health = new HealthHandler();
    $errors = new JsonResponse();
    $router = new Router([
        new RouteEntry('GET', '/health', $health),
        new RouteEntry('HEAD', '/health', $health),
        new RouteEntry('POST', '/echo', new EchoHandler(65_536)),
    ]);

    return new App(new Pipeline(new RouteHandlerResolver(), [
        new RequestContextMiddleware($logger, new RequestContextFactory(new RandomRequestIdGenerator())),
        new ErrorHandlerMiddleware($errors, $logger),
        new RoutingMiddleware($router, $errors),
    ]));
};
