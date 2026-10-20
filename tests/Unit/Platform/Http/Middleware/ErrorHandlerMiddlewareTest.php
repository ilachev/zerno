<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http\Middleware;

use App\Platform\Http\JsonResponse;
use App\Platform\Http\Middleware\ErrorHandlerMiddleware;
use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Platform\Logging\TestLogger;

final class ErrorHandlerMiddlewareTest extends TestCase
{
    #[DataProvider('provideFailureIsLoggedButTheResponseIsGenericCases')]
    public function testFailureIsLoggedButTheResponseIsGeneric(\Throwable $failure): void
    {
        $logger = new TestLogger();
        $middleware = new ErrorHandlerMiddleware(new JsonResponse(), $logger);
        $handler = new readonly class ($failure) implements RequestHandler {
            public function __construct(private \Throwable $failure) {}

            public function handle(Request $request): Response
            {
                throw $this->failure;
            }
        };

        $response = $middleware->process(new Request('GET', '/failure'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"error":"Internal Server Error"}', (string) $response->getBody());
        self::assertCount(1, $logger->logs);
        self::assertSame('error', $logger->logs[0]['level']);
        self::assertSame((string) $failure, $logger->logs[0]['message']);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function provideFailureIsLoggedButTheResponseIsGenericCases(): iterable
    {
        yield 'exception' => [new \RuntimeException('private exception details')];
        yield 'error' => [new \Error('private error details')];
    }

    public function testExplicitHandlerResponsesAreReturnedUnchanged(): void
    {
        $logger = new TestLogger();
        $middleware = new ErrorHandlerMiddleware(new JsonResponse(), $logger);

        foreach ([200, 400, 404, 405, 500] as $status) {
            $expected = new Response($status, ['Content-Type' => 'application/json', 'X-Application' => 'kept'], '{"application":"response"}');
            $handler = new readonly class ($expected) implements RequestHandler {
                public function __construct(private Response $response) {}

                public function handle(Request $request): Response
                {
                    return $this->response;
                }
            };

            self::assertSame($expected, $middleware->process(new Request('GET', '/application'), $handler));
        }

        self::assertSame([], $logger->logs);
    }
}
