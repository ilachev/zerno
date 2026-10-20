<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use App\Platform\Http\RequestHandler;

final readonly class RouteEntry
{
    public function __construct(
        public string $method,
        public string $path,
        public RequestHandler $handler,
        public ?string $operationId = null,
    ) {}
}
