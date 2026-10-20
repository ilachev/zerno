<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http\Middleware;

use App\Platform\Http\Middleware\RequestContextMiddleware;
use App\Platform\Http\RequestContextAttributes;
use App\Platform\Http\RequestHandler;
use App\Platform\Runtime\RequestContext;
use App\Platform\Runtime\RequestContextFactory;
use App\Platform\Runtime\RequestIdGenerator;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Unit\Platform\Logging\TestLogger;

final class RequestContextMiddlewareTest extends TestCase
{
    public function testAttachesRequestContextAndResponseHeaders(): void
    {
        $middleware = new RequestContextMiddleware(
            new TestLogger(),
            new RequestContextFactory(new TestRequestIdGenerator('generated-request-id')),
        );
        $handler = new class implements RequestHandler {
            public ?RequestContext $capturedContext = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capturedContext = RequestContextAttributes::get($request);

                return new Response(200);
            }
        };
        $response = $middleware->process(
            new ServerRequest('GET', '/test'),
            $handler,
        );

        self::assertInstanceOf(RequestContext::class, $handler->capturedContext);
        self::assertSame(
            $handler->capturedContext->requestId,
            $response->getHeaderLine('X-Request-ID'),
        );
        self::assertNotSame('', $response->getHeaderLine('X-Response-Time'));
        self::assertNotSame('', $response->getHeaderLine('Server-Timing'));
    }

    public function testKeepsIncomingRequestIdWhenPresent(): void
    {
        $middleware = new RequestContextMiddleware(
            new TestLogger(),
            new RequestContextFactory(new TestRequestIdGenerator('generated-request-id')),
        );
        $handler = new class implements RequestHandler {
            public ?RequestContext $capturedContext = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capturedContext = RequestContextAttributes::get($request);

                return new Response(200);
            }
        };
        $response = $middleware->process(
            new ServerRequest('GET', '/test', ['X-Request-ID' => 'incoming-request-id']),
            $handler,
        );

        self::assertSame('incoming-request-id', $handler->capturedContext?->requestId);
        self::assertSame('incoming-request-id', $response->getHeaderLine('X-Request-ID'));
    }
}

final readonly class TestRequestIdGenerator implements RequestIdGenerator
{
    public function __construct(
        private string $requestId,
    ) {}

    public function generate(): string
    {
        return $this->requestId;
    }
}
