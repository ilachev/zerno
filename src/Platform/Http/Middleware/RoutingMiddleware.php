<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Error\Error;
use App\Platform\Http\JsonResponse;
use App\Platform\Http\Middleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Routing\RouteResult;
use App\Platform\Routing\RouterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RoutingMiddleware implements Middleware
{
    public function __construct(
        private RouterInterface $router,
        private JsonResponse $jsonResponse,
    ) {}

    /**
     * @throws \JsonException
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandler $handler,
    ): ResponseInterface {
        $routeResult = $this->router->dispatch($request);

        if (!$routeResult->isFound()) {
            $status = $routeResult->getStatusCode();
            $response = $this->jsonResponse->error(
                $status === 405 ? Error::METHOD_NOT_ALLOWED : Error::NOT_FOUND,
                $status,
            );

            return $status === 405
                ? $response->withHeader('Allow', implode(', ', $routeResult->getAllowedMethods()))
                : $response;
        }

        return $handler->handle(
            $request->withAttribute(RouteResult::class, $routeResult)
                ->withAttribute('routeParams', $routeResult->getParams())
                ->withAttribute('handler', $routeResult->getHandler()),
        );
    }
}
