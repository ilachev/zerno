<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\RequestHandler;
use App\Platform\Runtime\App;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Platform\Runtime\Fixtures\RequestTrace;

final class AppTest extends TestCase
{
    public function testHandlesRequestsWithoutWorkerServices(): void
    {
        $trace = new RequestTrace();
        $app = $this->createApp($trace);
        self::assertInstanceOf(RequestHandler::class, $app);

        $response = $app->handle(new ServerRequest('GET', '/example', ['X-Test-Value' => 'standalone']));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('standalone:outer:inner', (string) $response->getBody());
        self::assertSame('standalone', $response->getHeaderLine('X-Handler'));
        self::assertSame(['inner', 'outer'], $response->getHeader('X-Middleware'));
    }

    public function testPreservesMiddlewareOrderAcrossRequestsAndBothHandlerMethods(): void
    {
        $trace = new RequestTrace();
        $app = $this->createApp($trace);
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
        ], $trace->events);
    }

    private function createApp(RequestTrace $trace): App
    {
        /** @var \Closure(RequestTrace): RequestHandler $compose */
        $compose = require __DIR__ . '/Fixtures/application.php';

        return new App($compose($trace));
    }
}
