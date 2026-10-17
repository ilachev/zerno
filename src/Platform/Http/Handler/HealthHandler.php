<?php

declare(strict_types=1);

namespace App\Platform\Http\Handler;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HealthHandler implements HandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}');
    }
}
