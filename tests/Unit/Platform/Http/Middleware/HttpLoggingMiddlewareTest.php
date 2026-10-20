<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http\Middleware;

use App\Platform\Http\Body;
use App\Platform\Http\Middleware\HttpLoggingMiddleware;
use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Logging\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Platform\Logging\TestLogger;

final class HttpLoggingMiddlewareTest extends TestCase
{
    #[DataProvider('providePreservesSeekableResponseAndItsPositionCases')]
    public function testPreservesSeekableResponseAndItsPosition(string $method, int $offset): void
    {
        $logger = new TestLogger();
        $body = Body::create('private payload');
        $body->seek($offset);
        $expected = new Response(201, ['X-Application' => 'kept'], $body);
        $request = new Request($method, '/file')->withQueryParams(['id' => '42']);

        try {
            $actual = new HttpLoggingMiddleware($logger)->process($request, $this->handler($expected));

            self::assertSame($expected, $actual);
            self::assertSame($offset, $body->tell());
            self::assertTrue($body->isReadable());
            self::assertSame([
                ['level' => 'info', 'message' => 'Request.', 'context' => ['request' => ['id' => '42']]],
                ['level' => 'info', 'message' => 'Application responded.', 'context' => [
                    'status' => 201,
                    'body_size' => 15,
                    'body_seekable' => true,
                ]],
            ], $logger->logs);
            $body->rewind();
            self::assertSame('private payload', $body->getContents());
        } finally {
            $body->close();
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function providePreservesSeekableResponseAndItsPositionCases(): iterable
    {
        foreach (['GET', 'HEAD'] as $method) {
            foreach ([0, 4, 15] as $offset) {
                yield $method . ' at ' . $offset => [$method, $offset];
            }
        }
    }

    #[DataProvider('provideMethods')]
    public function testDoesNotConsumeNonSeekableBody(string $method): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        fwrite($sockets[1], 'prefix-private payload');
        fclose($sockets[1]);
        $body = Body::create($sockets[0]);
        self::assertFalse($body->isSeekable());
        self::assertSame('prefix-', $body->read(7));
        $logger = new TestLogger();
        $expected = new Response(200, [], $body);

        try {
            $actual = new HttpLoggingMiddleware($logger)->process(new Request($method, '/file'), $this->handler($expected));

            self::assertSame($expected, $actual);
            self::assertSame('private payload', $body->getContents());
            self::assertSame([
                'status' => 200,
                'body_size' => $body->getSize(),
                'body_seekable' => false,
            ], $logger->logs[1]['context']);
        } finally {
            $body->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    public function testLogsUnknownSizeWithoutReadingClosedBody(): void
    {
        $logger = new TestLogger();
        $body = Body::create('detached');
        $body->close();
        $expected = new Response(200, [], $body);

        self::assertSame($expected, new HttpLoggingMiddleware($logger)->process(new Request('GET', '/file'), $this->handler($expected)));
        self::assertSame([
            'status' => 200,
            'body_size' => null,
            'body_seekable' => false,
        ], $logger->logs[1]['context']);
    }

    #[DataProvider('provideMethods')]
    public function testClosesResponseBodyWhenResponseLoggingFails(string $method): void
    {
        $resource = tmpfile();
        self::assertIsResource($resource);
        fwrite($resource, 'private payload');
        rewind($resource);
        $body = Body::create($resource);
        $failure = new \RuntimeException('Log output unavailable');
        $logger = new FailingHttpLogger($failure, 2);

        try {
            new HttpLoggingMiddleware($logger)->process(new Request($method, '/file'), $this->handler(new Response(200, [], $body)));
            self::fail('Expected the logger failure');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse(\is_resource($resource));
        self::assertFalse($body->isReadable());
        self::assertNull($body->getSize());
    }

    public function testRequestLoggingFailureDoesNotInvokeHandler(): void
    {
        $failure = new \RuntimeException('Log output unavailable');
        $handler = new class implements RequestHandler {
            public bool $called = false;

            public function handle(Request $request): Response
            {
                $this->called = true;

                return new Response();
            }
        };

        try {
            new HttpLoggingMiddleware(new FailingHttpLogger($failure, 1))->process(new Request('GET', '/file'), $handler);
            self::fail('Expected the logger failure');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($handler->called);
    }

    private function handler(Response $response): RequestHandler
    {
        return new readonly class ($response) implements RequestHandler {
            public function __construct(private Response $response) {}

            public function handle(Request $request): Response
            {
                return $this->response;
            }
        };
    }
}

final class FailingHttpLogger implements Logger
{
    /**
     * @param array<mixed> $context
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function __construct(
        private readonly \RuntimeException $failure,
        private int $callsBeforeFailure,
    ) {}

    /** @param array<mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (--$this->callsBeforeFailure === 0) {
            throw $this->failure;
        }
    }
}
