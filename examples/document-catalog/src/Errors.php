<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Error\Error;
use App\Platform\Http\ErrorResponse;
use App\Platform\Http\Response;

final readonly class Errors implements ErrorResponse
{
    public function __construct(
        private Html $html,
    ) {}

    public function error(Error $error, int $status): Response
    {
        return $this->html->error($status);
    }
}
