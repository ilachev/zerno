<?php

declare(strict_types=1);

namespace App\Platform\Routing;

final readonly class RouteDefinition implements RouteDefinitionInterface
{
    public function __construct(
        private RouteProvider $routeProvider,
    ) {}

    public function defineRoutes(RouteCollectorInterface $collector): void
    {
        foreach ($this->routeProvider->getRoutes() as $route) {
            $collector->addRoute($route->method, $route->path, $route->handler, $route->operationId);
        }
    }
}
