<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http\Middleware;

use App\Platform\Http\JsonResponse;
use App\Platform\Http\Middleware\RoutingMiddleware;
use App\Platform\Http\RequestHandler;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\Router;
use App\Platform\Routing\RouteResult;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RoutingMiddlewareTest extends TestCase
{
    /** @var array<string, RequestHandler> */
    private array $handlers = [];

    private function handler(string $name): RequestHandler
    {
        return $this->handlers[$name] ??= new readonly class ($name) implements RequestHandler {
            public function __construct(private string $name) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], $this->name);
            }
        };
    }

    public function testUnknownPathReturnsJson404WithoutCallingHandler(): void
    {
        $middleware = $this->createMiddleware([new RouteEntry('GET', '/items/{id}', $this->handler('ItemHandler'))]);
        $handler = new RoutingRecordingHandler();
        $response = $middleware->process(new ServerRequest('POST', '/missing'), $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Not found'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertFalse($response->hasHeader('Allow'));
        self::assertNull($handler->request);
    }

    public function testWrongMethodReturnsJson405WithAllAllowedMethods(): void
    {
        $middleware = $this->createMiddleware([
            new RouteEntry('GET', '/items/new', $this->handler('NewItemHandler')),
            new RouteEntry('HEAD', '/items/new', $this->handler('HeadItemHandler')),
            new RouteEntry('GET', '/items/{id}', $this->handler('ItemHandler')),
            new RouteEntry('POST', '/items/{id}', $this->handler('SaveItemHandler')),
        ]);
        $handler = new RoutingRecordingHandler();
        $response = $middleware->process(new ServerRequest('DELETE', '/items/new'), $handler);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Method not allowed'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['GET, HEAD, POST'], $response->getHeader('Allow'));
        self::assertNull($handler->request);
    }

    public function testParameterizedPathIs405ForUnregisteredMethodIncludingHead(): void
    {
        $middleware = $this->createMiddleware([new RouteEntry('GET', '/items/{id}', $this->handler('ItemHandler'))]);
        foreach (['POST', 'HEAD'] as $method) {
            $handler = new RoutingRecordingHandler();
            $response = $middleware->process(new ServerRequest($method, '/items/42'), $handler);

            self::assertSame(405, $response->getStatusCode());
            self::assertSame('GET', $response->getHeaderLine('Allow'));
            self::assertNull($handler->request);
        }
    }

    public function testFoundRoutePassesAttributesAndReturnsHandlerResponse(): void
    {
        $middleware = $this->createMiddleware([
            new RouteEntry('GET', '/items/current', $this->handler('CurrentItemHandler')),
            new RouteEntry('POST', '/items/{id}', $this->handler('SaveItemHandler')),
        ]);
        $request = new ServerRequest('POST', '/items/current?preview=1');
        $handler = new RoutingRecordingHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame($handler->response, $response);
        self::assertNotNull($handler->request);
        self::assertSame($this->handler('SaveItemHandler'), $handler->request->getAttribute('handler'));
        self::assertSame(['id' => 'current'], $handler->request->getAttribute('routeParams'));
        $route = $handler->request->getAttribute(RouteResult::class);
        self::assertInstanceOf(RouteResult::class, $route);
        self::assertSame($this->handler('SaveItemHandler'), $route->getHandler());
        self::assertSame([], $route->getAllowedMethods());
        self::assertNull($request->getAttribute('handler'));
    }

    /** @param list<RouteEntry> $routes */
    private function createMiddleware(array $routes): RoutingMiddleware
    {
        return new RoutingMiddleware(new Router($routes), new JsonResponse());
    }
}

final class RoutingRecordingHandler implements RequestHandler
{
    public ?ServerRequestInterface $request = null;

    public readonly ResponseInterface $response;

    public function __construct()
    {
        $this->response = new Response(202, ['X-Handler' => 'called'], 'handled');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->response;
    }
}
