<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Http\RequestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class App implements RequestHandler
{
    public function __construct(private RequestHandler $handler) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handler->handle($request);
    }
}
