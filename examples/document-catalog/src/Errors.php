<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Http\Middleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Logging\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class Errors implements Middleware
{
    public function __construct(
        private Html $html,
        private Logger $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandler $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $exception) {
            $this->logger->error('A document catalog request failed.', ['exception' => $exception::class]);

            return $this->html->error(500);
        }

        $status = $response->getStatusCode();

        if (\in_array($status, [404, 405], true) && str_starts_with($response->getHeaderLine('Content-Type'), 'application/json')) {
            $error = $this->html->error($status);

            return $status === 405 ? $error->withHeader('Allow', $response->getHeader('Allow')) : $error;
        }

        return $response;
    }
}
