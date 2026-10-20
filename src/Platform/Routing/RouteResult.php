<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use App\Platform\Http\RequestHandler;

final readonly class RouteResult
{
    private RouteParameters $routeParameters;

    /**
     * @param array<string, string>|RouteParameters $params
     * @param list<string> $allowedMethods
     */
    public function __construct(
        private RouteStatus $status,
        private ?RequestHandler $handler = null,
        array|RouteParameters $params = [],
        private array $allowedMethods = [],
    ) {
        $this->routeParameters = $params instanceof RouteParameters
            ? $params
            : RouteParameters::fromArray($params);
    }

    public function isFound(): bool
    {
        return $this->status === RouteStatus::FOUND;
    }

    public function getHandler(): RequestHandler
    {
        if (!$this->isFound()) {
            throw RouteException::routeNotFound();
        }
        if ($this->handler === null) {
            throw RouteException::handlerNotFound();
        }

        return $this->handler;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        if (!$this->isFound()) {
            throw RouteException::routeNotFound();
        }

        return $this->routeParameters->toArray();
    }

    public function getRouteParameters(): RouteParameters
    {
        if (!$this->isFound()) {
            throw RouteException::routeNotFound();
        }

        return $this->routeParameters;
    }

    public function getStatusCode(): int
    {
        return $this->status->getStatusCode();
    }

    /** @return list<string> */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
