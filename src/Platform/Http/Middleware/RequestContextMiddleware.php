<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\Middleware;
use App\Platform\Http\Request;
use App\Platform\Http\RequestContextAttributes;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Logging\Logger;
use App\Platform\Logging\RoadRunnerLogger;
use App\Platform\Runtime\RequestContextFactory;

final readonly class RequestContextMiddleware implements Middleware
{
    public function __construct(
        private Logger $logger,
        private RequestContextFactory $requestContextFactory,
    ) {}

    public function process(
        Request $request,
        RequestHandler $handler,
    ): Response {
        $startTime = hrtime(true);
        $context = $this->requestContextFactory->create(
            requestId: $request->getHeaderLine('X-Request-ID'),
        );

        if ($this->logger instanceof RoadRunnerLogger) {
            $this->logger->requestId = $context->requestId;
        }

        $response = $handler->handle(RequestContextAttributes::attach($request, $context));
        $executionTime = (hrtime(true) - $startTime) / 1_000_000;

        return $response
            ->withHeader('X-Request-ID', $context->requestId)
            ->withHeader('X-Response-Time', \sprintf('%.2f ms', $executionTime))
            ->withHeader('Server-Timing', \sprintf('app;dur=%.2f', $executionTime));
    }
}
