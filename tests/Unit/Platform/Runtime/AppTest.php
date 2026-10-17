<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\RequestHandler;
use App\Platform\Runtime\App;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Tests\Unit\Platform\Runtime\Fixtures\RequestTrace;

final class AppTest extends TestCase
{
    public function testHandlesRequestsWithoutWorkerServices(): void
    {
        $app = new App(__DIR__ . '/Fixtures/application.php');
        self::assertInstanceOf(RequestHandler::class, $app);
        self::assertFalse($app->getContainer()->has(Worker::class));
        self::assertFalse($app->getContainer()->has(PSR7Worker::class));

        $response = $app->handle(new ServerRequest('GET', '/example', ['X-Test-Value' => 'standalone']));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('standalone:outer:inner', (string) $response->getBody());
        self::assertSame('standalone', $response->getHeaderLine('X-Handler'));
        self::assertSame(['inner', 'outer'], $response->getHeader('X-Middleware'));
    }

    public function testDoesNotResolveRegisteredWorkerFactories(): void
    {
        $app = new App(__DIR__ . '/Fixtures/application-with-worker-traps.php');

        $response = $app->handleRequest(new ServerRequest('GET', '/example', ['X-Test-Value' => 'without-transport']));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('without-transport:outer:inner', (string) $response->getBody());
    }

    public function testPreservesMiddlewareOrderAcrossRequestsAndBothHandlerMethods(): void
    {
        $app = new App(__DIR__ . '/Fixtures/application.php');
        $firstRequest = new ServerRequest('GET', '/first', ['X-Test-Value' => 'first']);
        $secondRequest = new ServerRequest('GET', '/second', ['X-Test-Value' => 'second']);

        $firstResponse = $app->handle($firstRequest);
        $secondResponse = $app->handleRequest($secondRequest);

        self::assertSame('first:outer:inner', (string) $firstResponse->getBody());
        self::assertSame('second:outer:inner', (string) $secondResponse->getBody());
        self::assertNull($firstRequest->getAttribute('trace-value'));
        self::assertNull($secondRequest->getAttribute('trace-value'));
        self::assertSame([
            'outer:enter:first',
            'inner:enter:first',
            'handler:first',
            'inner:exit:first',
            'outer:exit:first',
            'outer:enter:second',
            'inner:enter:second',
            'handler:second',
            'inner:exit:second',
            'outer:exit:second',
        ], $app->getContainer()->get(RequestTrace::class)->events);
    }
}
