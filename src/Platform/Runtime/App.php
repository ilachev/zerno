<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\DI\Container;
use App\Platform\DI\DIContainer;
use App\Platform\Http\RequestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @template T of object */
final readonly class App implements RequestHandler
{
    /** @var DIContainer<T> */
    private Container $container;

    private RequestHandler $handler;

    /**
     * @return DIContainer<T>
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handler->handle($request);
    }

    public function __construct(string $configPath)
    {
        /** @var callable(DIContainer<T>): void $containerConfig */
        $containerConfig = require $configPath;

        $this->container = new DIContainer();
        $containerConfig($this->container);

        $this->handler = $this->container->get(RequestHandler::class);
    }
}
