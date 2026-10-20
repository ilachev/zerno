<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use App\Platform\Http\RequestHandler;

final readonly class RouteMapEntry
{
    /** @param list<string> $parameterNames */
    public function __construct(
        public RequestHandler $handler,
        public RouteParameters $params,
        public ?string $pattern = null,
        public array $parameterNames = [],
    ) {}
}
