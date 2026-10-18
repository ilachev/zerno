<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Error\Error;
use App\Platform\Http\ErrorResponse;
use App\Platform\Http\Middleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Logging\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ErrorHandlerMiddleware implements Middleware
{
    public function __construct(
        private ErrorResponse $errors,
        private Logger $logger,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandler $handler,
    ): ResponseInterface {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error((string) $e);

            return $this->errors->error(
                Error::INTERNAL_ERROR,
                500,
            );
        }
    }
}
