<?php

declare(strict_types=1);

use App\Platform\Http\JsonResponse;
use App\Platform\Http\Middleware\ErrorHandlerMiddleware;
use App\Platform\Http\Middleware\HttpLoggingMiddleware;
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
use Examples\DocumentCatalog\Errors;
use Examples\DocumentCatalog\Html;
use Project\HttpFixtures\AttachmentHandler;
use Project\HttpFixtures\FixtureHandler;

require_once __DIR__ . '/FixtureHandler.php';
require_once __DIR__ . '/AttachmentHandler.php';
require_once __DIR__ . '/AttachmentFiles.php';

return static function (Logger $logger): App {
    if (getenv('WIRE_ERROR_FORMAT') === 'html') {
        require_once dirname(__DIR__, 2) . '/examples/document-catalog/bootstrap.php';
        $errors = new Errors(new Html());
    } else {
        $errors = new JsonResponse();
    }
    $fixture = new FixtureHandler();
    $attachment = new AttachmentHandler();
    $routes = [
        new RouteEntry('GET', '/__wire/static', $fixture),
        new RouteEntry('HEAD', '/__wire/static', $fixture),
        new RouteEntry('GET', '/__wire/items/{id}', $fixture),
        new RouteEntry('DELETE', '/__wire/items/{id}', $fixture),
        new RouteEntry('GET', '/__wire/items/current', $fixture),
        new RouteEntry('GET', '/__wire/files/{name}.json', $fixture),
        new RouteEntry('GET', '/__wire/a+b/{id}', $fixture),
        new RouteEntry('GET', '/__wire/status/204', $fixture),
        new RouteEntry('GET', '/__wire/status/304', $fixture),
        new RouteEntry('GET', '/__wire/headers', $fixture),
        new RouteEntry('GET', '/__wire/failure', $fixture),
        new RouteEntry('HEAD', '/__wire/failure', $fixture),
        new RouteEntry('GET', '/__wire/context/{id}', $fixture),
        new RouteEntry('GET', '/__wire/context', $fixture),
        new RouteEntry('GET', '/__wire/attachment/{size}', $attachment),
        new RouteEntry('HEAD', '/__wire/attachment/{size}', $attachment),
    ];

    return new App(new Pipeline(
        new RouteHandlerResolver(),
        [
            new RequestContextMiddleware($logger, new RequestContextFactory(new RandomRequestIdGenerator())),
            new ErrorHandlerMiddleware($errors, $logger),
            new RoutingMiddleware(new Router($routes), $errors),
            ...(getenv('WIRE_HTTP_LOGGING') === '0' ? [] : [new HttpLoggingMiddleware($logger)]),
        ],
    ));
};
