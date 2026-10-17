<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use App\Platform\Routing\Generator\RouteProvider;

final readonly class StaticRouteProvider implements RouteProvider
{
    /** @param list<RouteEntry> $routes */
    public function __construct(private array $routes) {}

    public function getRoutes(): array
    {
        return $this->routes;
    }
}
