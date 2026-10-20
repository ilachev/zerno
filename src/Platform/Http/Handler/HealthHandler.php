<?php

declare(strict_types=1);

namespace App\Platform\Http\Handler;

use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;

final readonly class HealthHandler implements RequestHandler
{
    public function handle(Request $request): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}');
    }
}
