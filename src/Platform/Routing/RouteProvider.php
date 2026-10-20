<?php

declare(strict_types=1);

namespace App\Platform\Routing;

interface RouteProvider
{
    /**
     * @return list<RouteEntry>
     */
    public function getRoutes(): array;
}
