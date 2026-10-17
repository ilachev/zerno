<?php

declare(strict_types=1);

namespace App\Platform\Error;

enum Error: string
{
    case NOT_FOUND = 'Not found';
    case METHOD_NOT_ALLOWED = 'Method not allowed';
    case INTERNAL_ERROR = 'Internal Server Error';

    public function text(): string
    {
        return $this->value;
    }
}
