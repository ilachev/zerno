<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\Middleware\HttpLoggingMiddleware;
use App\Platform\Http\Middleware\Pipeline;
use App\Platform\Http\RequestHandler;
use App\Platform\Runtime\RoadRunnerRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Spiral\RoadRunner\Exception\RoadRunnerException;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;
use Tests\Unit\Platform\Logging\TestLogger;

final class RoadRunnerRuntimeTest extends TestCase
{
    public function testHandlesEachRequestBeforeWaitingForTheNextOne(): void
    {
        $firstRequest = new ServerRequest('GET', '/first');
        $secondRequest = new ServerRequest('POST', '/second');
        $handler = new RuntimeRecordingHandler();
        $pendingRequests = [$firstRequest, $secondRequest];
        $waitCalls = 0;
        /** @var list<ResponseInterface> $responses */
        $responses = [];
        $bodies = [];
        $worker = $this->createMock(PSR7WorkerInterface::class);
        $worker->expects(self::exactly(3))
            ->method('waitRequest')
            ->willReturnCallback(static function () use ($pendingRequests, &$waitCalls, &$responses): ?ServerRequestInterface {
                self::assertCount($waitCalls, $responses);

                return $pendingRequests[$waitCalls++] ?? null;
            });

        $worker->expects(self::exactly(2))
            ->method('respond')
            ->willReturnCallback(static function (ResponseInterface $response) use ($handler, &$responses, &$bodies): void {
                self::assertCount(\count($responses) + 1, $handler->requests);
                self::assertSame($handler->responses[\count($responses)], $response);
                $responses[] = $response;
                $bodies[] = (string) $response->getBody();
            });

        (new RoadRunnerRuntime($worker, $handler))->run();

        self::assertSame([$firstRequest, $secondRequest], $handler->requests);
        self::assertSame(
            ['/first', '/second'],
            $bodies,
        );
        foreach ($handler->responses as $response) {
            self::assertFalse($response->getBody()->isReadable());
        }
    }

    public function testStopsWhenTheFirstRequestIsNull(): void
    {
        $handler = new RuntimeRecordingHandler();
        $worker = $this->createMock(PSR7WorkerInterface::class);
        $worker->expects(self::once())->method('waitRequest')->willReturn(null);
        $worker->expects(self::never())->method('respond');

        (new RoadRunnerRuntime($worker, $handler))->run();

        self::assertSame([], $handler->requests);
    }

    public function testPropagatesWorkerErrors(): void
    {
        $handler = new RuntimeRecordingHandler();
        $exception = new RoadRunnerException('Worker connection failed');
        $worker = $this->createMock(PSR7WorkerInterface::class);
        $worker->expects(self::once())->method('waitRequest')->willThrowException($exception);
        $worker->expects(self::never())->method('respond');

        try {
            (new RoadRunnerRuntime($worker, $handler))->run();
            self::fail('Worker errors must propagate to the caller');
        } catch (RoadRunnerException $caught) {
            self::assertSame($exception, $caught);
            self::assertSame([], $handler->requests);
        }
    }

    #[DataProvider('provideDoesNotReadForbiddenBodiesThroughTheRealAdapterCases')]
    public function testDoesNotReadForbiddenBodiesThroughTheRealAdapter(int $status, string $method, int $chunkSize): void
    {
        $body = $this->createMock(StreamInterface::class);
        foreach (['read', 'rewind', 'getContents', '__toString'] as $methodName) {
            $body->expects(self::never())->method($methodName);
        }
        $body->expects(self::once())->method('close');
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
        $factory = new Psr17Factory();
        $worker = new PSR7Worker($transport, $factory, $factory, $factory);
        $worker->chunkSize = $chunkSize;
        $handler = new FixedResponseHandler($response);

        new RoadRunnerRuntime($worker, $handler)->run();
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
        // The bundled HTTP adapter rejects generators for informational responses.
        yield 'informational, buffered' => [103, 'GET', 0];
    }

    public function testClosesTheOriginalBodyWhenTheTransportFails(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->expects(self::once())->method('close');
        $handler = new FixedResponseHandler(new Response(200, [], $body));
        $worker = $this->createMock(PSR7WorkerInterface::class);
        $worker->expects(self::once())->method('waitRequest')->willReturn(new ServerRequest('GET', '/document'));
        $worker->expects(self::once())->method('respond')->willThrowException(new RoadRunnerException('Transport failed'));

        $this->expectException(RoadRunnerException::class);
        $this->expectExceptionMessage('Transport failed');

        new RoadRunnerRuntime($worker, $handler)->run();
    }

    #[DataProvider('provideClosesRealLoggedFileThroughTheChunkedAdapterCases')]
    public function testClosesRealLoggedFileThroughTheChunkedAdapter(bool $transportFails): void
    {
        $content = str_repeat('0123456789abcdef', 1024) . 'final-file-bytes!';
        $resource = tmpfile();
        self::assertIsResource($resource);
        self::assertSame(16401, fwrite($resource, $content));
        $body = Stream::create($resource);
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
        $factory = new Psr17Factory();
        $worker = new PSR7Worker($transport, $factory, $factory, $factory);
        $worker->chunkSize = 8192;
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
}

final readonly class FixedResponseHandler implements RequestHandler
{
    public function __construct(private ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

final class RuntimeRecordingHandler implements RequestHandler
{
    /** @var list<ServerRequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    public array $responses = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = new Response(200, [], $request->getUri()->getPath());
        $this->responses[] = $response;

        return $response;
    }
}
