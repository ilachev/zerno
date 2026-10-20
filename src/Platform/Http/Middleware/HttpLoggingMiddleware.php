<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\Middleware;
use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Logging\Logger;

final readonly class HttpLoggingMiddleware implements Middleware
{
    public function __construct(
        private Logger $logger,
    ) {}

    public function process(
        Request $request,
        RequestHandler $handler,
    ): Response {
        $this->logger->info('Request.', [
            'request' => $request->getQueryParams(),
        ]);

        $response = $handler->handle($request);
        $body = $response->getBody();

        try {
            $this->logger->info('Application responded.', [
                'status' => $response->getStatusCode(),
                'body_size' => $body->getSize(),
                'body_seekable' => $body->isSeekable(),
            ]);
        } catch (\Throwable $exception) {
            // The transport cannot take ownership when logging interrupts the response.
            $body->close();

            throw $exception;
        }

        return $response;
    }
}
