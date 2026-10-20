<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Error\Error;

interface ErrorResponse
{
    public function error(Error $error, int $status): Response;
}
