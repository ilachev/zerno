<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

final readonly class RequestContext
{
    public function __construct(
        public string $requestId,
    ) {}
}
