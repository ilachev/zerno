<?php

declare(strict_types=1);

namespace App\Platform\Routing;

final readonly class RouteMapEntry
{
    /** @param list<string> $parameterNames */
    public function __construct(
        public string $handler,
        public RouteParameters $params,
        public ?string $pattern = null,
        public array $parameterNames = [],
    ) {}
}
