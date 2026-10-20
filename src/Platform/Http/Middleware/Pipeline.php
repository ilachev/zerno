<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\Middleware;
use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;

final readonly class Pipeline implements RequestHandler
{
    /**
     * @param Middleware[] $middlewares
     */
    public function __construct(
        private RequestHandler $handler,
        private array $middlewares = [],
    ) {}

    public function handle(Request $request): Response
    {
        $middlewares = $this->middlewares;
        $middleware = array_shift($middlewares);

        if ($middleware === null) {
            return $this->handler->handle($request);
        }

        return $middleware->process(
            $request,
            new self($this->handler, $middlewares),
        );
    }
}
