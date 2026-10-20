<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;

final readonly class App implements RequestHandler
{
    public function __construct(private RequestHandler $handler) {}

    public function handleRequest(Request $request): Response
    {
        return $this->handle($request);
    }

    public function handle(Request $request): Response
    {
        return $this->handler->handle($request);
    }
}
