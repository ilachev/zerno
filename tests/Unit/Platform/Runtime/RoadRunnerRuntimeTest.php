<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\RequestHandler;
use App\Platform\Runtime\RoadRunnerRuntime;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Exception\RoadRunnerException;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;

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
        $worker = $this->createMock(PSR7WorkerInterface::class);
        $worker->expects(self::exactly(3))
            ->method('waitRequest')
            ->willReturnCallback(static function () use ($pendingRequests, &$waitCalls, &$responses): ?ServerRequestInterface {
                self::assertCount($waitCalls, $responses);

                return $pendingRequests[$waitCalls++] ?? null;
            });

        $worker->expects(self::exactly(2))
            ->method('respond')
            ->willReturnCallback(static function (ResponseInterface $response) use ($handler, &$responses): void {
                self::assertCount(\count($responses) + 1, $handler->requests);
                self::assertSame($handler->responses[\count($responses)], $response);
                $responses[] = $response;
            });

        (new RoadRunnerRuntime($worker, $handler))->run();

        self::assertSame([$firstRequest, $secondRequest], $handler->requests);
        self::assertSame(
            ['/first', '/second'],
            array_map(static fn(ResponseInterface $response): string => (string) $response->getBody(), $responses),
        );
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
