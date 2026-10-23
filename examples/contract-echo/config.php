<?php

declare(strict_types=1);

use App\Generated\Echo\EchoSendHttp;
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
use Examples\ContractEcho\EchoEndpoint;

require_once __DIR__ . '/EchoEndpoint.php';

return static function (Logger $logger): App {
    $health = new HealthHandler();
    $errors = new JsonResponse();
    $echo = new EchoSendHttp(new EchoEndpoint(), 65_536);

    return new App(new Pipeline(new RouteHandlerResolver(), [
        new RequestContextMiddleware($logger, new RequestContextFactory(new RandomRequestIdGenerator())),
        new ErrorHandlerMiddleware($errors, $logger),
        new RoutingMiddleware(new Router([
            new RouteEntry('GET', '/health', $health),
            new RouteEntry('HEAD', '/health', $health),
            $echo->route(),
        ]), $errors),
    ]));
};
