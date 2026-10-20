<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\Body;
use App\Platform\Http\Middleware\HttpLoggingMiddleware;
use App\Platform\Http\Middleware\Pipeline;
use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Runtime\RoadRunnerRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Exception\RoadRunnerException;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;
use Tests\Fixtures\ObservedStream;
use Tests\Unit\Platform\Logging\TestLogger;

final class RoadRunnerRuntimeTest extends TestCase
{
    public function testHandlesEachRequestBeforeWaitingForTheNextOne(): void
    {
        $handler = new RuntimeRecordingHandler();
        $transport = $this->createMock(WorkerInterface::class);
        $pending = [$this->payload('GET', '/first'), $this->payload('POST', '/second')];
        $waits = 0;
        $bodies = [];
        $transport->expects(self::exactly(3))->method('waitPayload')->willReturnCallback(
            static function () use ($pending, &$waits, &$bodies): ?Payload {
                self::assertCount($waits, $bodies);

                return $pending[$waits++] ?? null;
            },
        );
        $transport->expects(self::exactly(2))->method('respond')->willReturnCallback(
            static function (Payload $payload) use ($handler, &$bodies): void {
                self::assertCount(\count($bodies) + 1, $handler->requests);
                $bodies[] = $payload->body;
            },
        );
        new RoadRunnerRuntime(RoadRunnerRuntime::createWorker($transport), $handler)->run();

        self::assertSame(['/first', '/second'], $bodies);
        self::assertSame(['GET', 'POST'], array_map(static fn(Request $r): string => $r->getMethod(), $handler->requests));
        foreach ($handler->responses as $response) {
            self::assertFalse($response->getBody()->isReadable());
        }
    }

    public function testStopsWhenTheFirstRequestIsNull(): void
    {
        $handler = new RuntimeRecordingHandler();
        $transport = $this->createMock(WorkerInterface::class);
        $transport->expects(self::once())->method('waitPayload')->willReturn(null);
        $transport->expects(self::never())->method('respond');

        new RoadRunnerRuntime(RoadRunnerRuntime::createWorker($transport), $handler)->run();

        self::assertSame([], $handler->requests);
    }

    public function testPropagatesWorkerErrors(): void
    {
        $handler = new RuntimeRecordingHandler();
        $exception = new RoadRunnerException('Worker connection failed');
        $transport = $this->createMock(WorkerInterface::class);
        $transport->expects(self::once())->method('waitPayload')->willThrowException($exception);
        $transport->expects(self::never())->method('respond');

        try {
            new RoadRunnerRuntime(RoadRunnerRuntime::createWorker($transport), $handler)->run();
            self::fail('Worker errors must propagate to the caller');
        } catch (RoadRunnerException $caught) {
            self::assertSame($exception, $caught);
            self::assertSame([], $handler->requests);
        }
    }

    #[DataProvider('provideDoesNotReadForbiddenBodiesThroughTheRealAdapterCases')]
    public function testDoesNotReadForbiddenBodiesThroughTheRealAdapter(int $status, string $method, int $chunkSize): void
    {
        $observed = new ObservedStream('must not be read');
        $body = $observed->open();
        $response = new Response($status, ['Content-Length' => '17', 'X-Example' => 'preserved'], $body);
        $transport = $this->createMock(WorkerInterface::class);
        $transport->expects(self::exactly(2))->method('waitPayload')->willReturn(
            new Payload('', json_encode([
                'remoteAddr' => '127.0.0.1:12345',
                'protocol' => 'HTTP/1.1',
                'method' => $method,
                'uri' => 'http://localhost/document',
                'headers' => [],
                'cookies' => [],
                'uploads' => [],
                'attributes' => [],
                'rawQuery' => '',
                'parsed' => false,
            ], JSON_THROW_ON_ERROR)),
            null,
        );
        $transport->expects(self::once())->method('respond')->willReturnCallback(
            static function (Payload $payload) use ($status): void {
                self::assertSame('', $payload->body);
                self::assertTrue($payload->eos);
                /** @var array{status: int, headers: array<string, list<string>>} $header */
                $header = json_decode($payload->header, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($status, $header['status']);
                self::assertSame(['preserved'], $header['headers']['X-Example']);
                if ($status < 200 || $status === 204) {
                    self::assertArrayNotHasKey('Content-Length', $header['headers']);
                } else {
                    self::assertSame(['17'], $header['headers']['Content-Length']);
                }
            },
        );
        $worker = RoadRunnerRuntime::createWorker($transport, $chunkSize);
        $handler = new FixedResponseHandler($response);

        new RoadRunnerRuntime($worker, $handler)->run();
        self::assertSame(0, $observed->reads);
        self::assertSame(0, $observed->seeks);
        self::assertSame(1, $observed->closes);
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function provideDoesNotReadForbiddenBodiesThroughTheRealAdapterCases(): iterable
    {
        foreach ([0, 8192] as $chunkSize) {
            yield 'HEAD success, chunk ' . $chunkSize => [200, 'HEAD', $chunkSize];
            yield 'HEAD error, chunk ' . $chunkSize => [500, 'HEAD', $chunkSize];
            yield 'no content, chunk ' . $chunkSize => [204, 'GET', $chunkSize];
            yield 'not modified, chunk ' . $chunkSize => [304, 'GET', $chunkSize];
        }
        yield 'informational, buffered' => [103, 'GET', 0];
        yield 'informational, chunked' => [103, 'GET', 8192];
    }

    public function testClosesTheOriginalBodyWhenTheTransportFails(): void
    {
        $body = Body::create('payload');
        $handler = new FixedResponseHandler(new Response(200, [], $body));
        $transport = $this->createMock(WorkerInterface::class);
        $transport->expects(self::once())->method('waitPayload')->willReturn($this->payload('GET', '/document'));
        $transport->expects(self::once())->method('respond')->willThrowException(new RoadRunnerException('Transport failed'));
        $worker = RoadRunnerRuntime::createWorker($transport);

        $this->expectException(RoadRunnerException::class);
        $this->expectExceptionMessage('Transport failed');

        try {
            new RoadRunnerRuntime($worker, $handler)->run();
        } finally {
            self::assertFalse($body->isReadable());
        }
    }

    #[DataProvider('provideClosesRealLoggedFileThroughTheChunkedAdapterCases')]
    public function testClosesRealLoggedFileThroughTheChunkedAdapter(bool $transportFails): void
    {
        $content = str_repeat('0123456789abcdef', 1024) . 'final-file-bytes!';
        $resource = tmpfile();
        self::assertIsResource($resource);
        self::assertSame(16401, fwrite($resource, $content));
        $body = Body::create($resource);
        $body->seek(29);
        $response = new Response(200, ['Content-Length' => (string) \strlen($content)], $body);
        $logger = new TestLogger();
        $handler = new Pipeline(new FixedResponseHandler($response), [new HttpLoggingMiddleware($logger)]);
        $transport = $this->createMock(WorkerInterface::class);
        $transport->expects(self::exactly($transportFails ? 1 : 2))->method('waitPayload')->willReturn(
            new Payload('', json_encode([
                'remoteAddr' => '127.0.0.1:12345',
                'protocol' => 'HTTP/1.1',
                'method' => 'GET',
                'uri' => 'http://localhost/document',
                'headers' => [],
                'cookies' => [],
                'uploads' => [],
                'attributes' => [],
                'rawQuery' => '',
                'parsed' => false,
            ], JSON_THROW_ON_ERROR)),
            null,
        );
        $failure = new RoadRunnerException('Transport failed after the first file chunk');
        /** @var list<Payload> $frames */
        $frames = [];
        $transport->expects(self::exactly($transportFails ? 2 : 3))->method('respond')->willReturnCallback(
            static function (Payload $payload) use ($transportFails, $failure, &$frames, $resource): void {
                self::assertTrue(\is_resource($resource));
                if ($transportFails && \count($frames) === 1) {
                    throw $failure;
                }
                $frames[] = $payload;
            },
        );
        $worker = RoadRunnerRuntime::createWorker($transport, 8192);
        $caught = null;

        try {
            new RoadRunnerRuntime($worker, $handler)->run();
        } catch (RoadRunnerException $exception) {
            $caught = $exception;
        }

        self::assertSame($transportFails ? $failure : null, $caught);
        self::assertFalse(\is_resource($resource));
        self::assertFalse($body->isReadable());
        self::assertSame([
            ['level' => 'info', 'message' => 'Request.', 'context' => ['request' => []]],
            ['level' => 'info', 'message' => 'Application responded.', 'context' => [
                'status' => 200,
                'body_size' => \strlen($content),
                'body_seekable' => true,
            ]],
        ], $logger->logs);
        self::assertSame(
            $transportFails ? [8192] : [8192, 8192, 17],
            array_map(static fn(Payload $frame): int => \strlen($frame->body), $frames),
        );
        self::assertSame(
            $transportFails ? substr($content, 0, 8192) : $content,
            implode('', array_map(static fn(Payload $frame): string => $frame->body, $frames)),
        );
        self::assertSame($transportFails ? [false] : [false, false, true], array_map(static fn(Payload $frame): bool => $frame->eos, $frames));
    }

    /** @return iterable<string, array{bool}> */
    public static function provideClosesRealLoggedFileThroughTheChunkedAdapterCases(): iterable
    {
        yield 'successful send' => [false];
        yield 'transport fails after the first chunk' => [true];
    }

    public function testPropagatesMidStreamReadFailureAndClosesTheBody(): void
    {
        $observed = new ObservedStream(str_repeat('x', 24576), failAfterReads: 2);
        $body = $observed->open();
        $transport = $this->createMock(WorkerInterface::class);
        $transport->expects(self::once())->method('waitPayload')->willReturn($this->payload('GET', '/file'));
        $frames = [];
        $transport->expects(self::exactly(2))->method('respond')->willReturnCallback(
            static function (Payload $payload) use (&$frames): void {
                $frames[] = [\strlen($payload->body), $payload->eos];
            },
        );

        try {
            new RoadRunnerRuntime(RoadRunnerRuntime::createWorker($transport, 8192), new FixedResponseHandler(new Response(200, [], $body)))->run();
            self::fail('Read errors must not be swallowed by native stream completion');
        } catch (\RuntimeException $exception) {
            self::assertSame('Observed stream read failed', $exception->getMessage());
        }
        self::assertSame([[8192, false], [0, true]], $frames);
        self::assertSame(1, $observed->closes);
        self::assertFalse($body->isReadable());
    }

    private function payload(string $method, string $path): Payload
    {
        return new Payload('', json_encode([
            'remoteAddr' => '127.0.0.1:12345',
            'protocol' => 'HTTP/1.1',
            'method' => $method,
            'uri' => 'http://localhost' . $path,
            'headers' => [],
            'cookies' => [],
            'uploads' => [],
            'attributes' => [],
            'rawQuery' => '',
            'parsed' => false,
        ], JSON_THROW_ON_ERROR));
    }
}

final readonly class FixedResponseHandler implements RequestHandler
{
    public function __construct(private Response $response) {}

    public function handle(Request $request): Response
    {
        return $this->response;
    }
}

final class RuntimeRecordingHandler implements RequestHandler
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response> */
    public array $responses = [];

    public function handle(Request $request): Response
    {
        $this->requests[] = $request;
        $response = new Response(200, [], $request->getUri()->getPath());
        $this->responses[] = $response;

        return $response;
    }
}
