<?php

declare(strict_types=1);

use App\Platform\Http\Handler\HealthHandler;
use App\Platform\Routing\RouteEntry;
use Examples\DocumentCatalog\DetailHandler;
use Examples\DocumentCatalog\DownloadHandler;
use Examples\DocumentCatalog\ListHandler;

return [
    new RouteEntry('GET', '/health', HealthHandler::class),
    new RouteEntry('HEAD', '/health', HealthHandler::class),
    new RouteEntry('GET', '/documents', ListHandler::class),
    new RouteEntry('HEAD', '/documents', ListHandler::class),
    new RouteEntry('GET', '/documents/{id}', DetailHandler::class),
    new RouteEntry('HEAD', '/documents/{id}', DetailHandler::class),
    new RouteEntry('GET', '/documents/{id}/download', DownloadHandler::class),
    new RouteEntry('HEAD', '/documents/{id}/download', DownloadHandler::class),
];
