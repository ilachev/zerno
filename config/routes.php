<?php

declare(strict_types=1);

use App\Platform\Http\Handler\HealthHandler;
use App\Platform\Routing\RouteEntry;

$health = new HealthHandler();

return [
    new RouteEntry('GET', '/health', $health),
    new RouteEntry('HEAD', '/health', $health),
];
