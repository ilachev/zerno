<?php

declare(strict_types=1);

use App\Platform\Http\Handler\HealthHandler;
use App\Platform\Routing\RouteEntry;
use Examples\DocumentCatalog\DetailHandler;
use Examples\DocumentCatalog\DownloadHandler;
use Examples\DocumentCatalog\ListHandler;

return static fn(HealthHandler $health, ListHandler $list, DetailHandler $detail, DownloadHandler $download): array => [
    new RouteEntry('GET', '/health', $health),
    new RouteEntry('HEAD', '/health', $health),
    new RouteEntry('GET', '/documents', $list),
    new RouteEntry('HEAD', '/documents', $list),
    new RouteEntry('GET', '/documents/{id}', $detail),
    new RouteEntry('HEAD', '/documents/{id}', $detail),
    new RouteEntry('GET', '/documents/{id}/download', $download),
    new RouteEntry('HEAD', '/documents/{id}/download', $download),
];
