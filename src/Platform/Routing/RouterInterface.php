<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use App\Platform\Http\Request;

interface RouterInterface
{
    public function dispatch(Request $request): RouteResult;
}
