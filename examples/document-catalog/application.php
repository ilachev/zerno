<?php

declare(strict_types=1);

use App\Platform\Http\Handler\HealthHandler;
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
use Examples\DocumentCatalog\CatalogRepository;
use Examples\DocumentCatalog\DetailHandler;
use Examples\DocumentCatalog\DownloadHandler;
use Examples\DocumentCatalog\Errors;
use Examples\DocumentCatalog\FileStore;
use Examples\DocumentCatalog\Html;
use Examples\DocumentCatalog\ListHandler;

require_once __DIR__ . '/bootstrap.php';

return static function (Logger $logger, Storage $storage, string $filesDirectory): App {
    $html = new Html();
    $errors = new Errors($html);
    $repository = new CatalogRepository($storage);
    $files = new FileStore($filesDirectory, $logger);
    /** @var Closure(HealthHandler, ListHandler, DetailHandler, DownloadHandler): list<RouteEntry> $routesFor */
    $routesFor = require __DIR__ . '/routes.php';
    $routes = $routesFor(
        new HealthHandler(),
        new ListHandler($repository, $html),
        new DetailHandler($repository, $html),
        new DownloadHandler($repository, $files, $html),
    );

    return new App(new Pipeline(
        new RouteHandlerResolver(),
        [
            new RequestContextMiddleware($logger, new RequestContextFactory(new RandomRequestIdGenerator())),
            new ErrorHandlerMiddleware($errors, $logger),
            new RoutingMiddleware(new Router($routes), $errors),
        ],
    ));
};
