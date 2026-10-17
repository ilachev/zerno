<?php

declare(strict_types=1);

use App\Platform\Http\Handler\HealthHandler;
use App\Platform\Routing\RouteEntry;

return [
    new RouteEntry('GET', '/health', HealthHandler::class),
    new RouteEntry('HEAD', '/health', HealthHandler::class),
];
