<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteHandlerResolver implements RequestHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $request->getAttribute('handler');
        if (!$handler instanceof RequestHandler) {
            throw new \LogicException('A routed request must carry a request handler.');
        }

        return $handler->handle($request);
    }
}
