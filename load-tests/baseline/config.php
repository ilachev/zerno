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
use App\Platform\Storage\Storage;
use Zerno\Baseline\JsonHandler;
use Zerno\Baseline\SelectHandler;

require_once __DIR__ . '/Handler.php';

return static function (Logger $logger): App {
    $errors = new JsonResponse();
    /** @var Storage $storage */
    $storage = require __DIR__ . '/../../config/storage.php';
    $routes = [
        new RouteEntry('GET', '/json', new JsonHandler()),
        new RouteEntry('GET', '/select/{id}', new SelectHandler($storage)),
    ];

    return new App(new Pipeline(
        new RouteHandlerResolver(),
        [
            new RequestContextMiddleware($logger, new RequestContextFactory(new RandomRequestIdGenerator())),
            new ErrorHandlerMiddleware($errors, $logger),
            new RoutingMiddleware(new Router($routes), $errors),
        ],
    ));
};
