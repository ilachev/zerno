<?php

declare(strict_types=1);

namespace App\Platform\Http;

final readonly class RouteHandlerResolver implements RequestHandler
{
    public function handle(Request $request): Response
    {
        $handler = $request->getAttribute('handler');
        if (!$handler instanceof RequestHandler) {
            throw new \LogicException('A routed request must carry a request handler.');
        }

        return $handler->handle($request);
    }
}
