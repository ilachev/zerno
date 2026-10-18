<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Error\Error;
use Psr\Http\Message\ResponseInterface;

interface ErrorResponse
{
    public function error(Error $error, int $status): ResponseInterface;
}
