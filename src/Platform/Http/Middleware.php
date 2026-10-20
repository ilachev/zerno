<?php

declare(strict_types=1);

namespace App\Platform\Http;

interface Middleware
{
    public function process(
        Request $request,
        RequestHandler $handler,
    ): Response;
}
