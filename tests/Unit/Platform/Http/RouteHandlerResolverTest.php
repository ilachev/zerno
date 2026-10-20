<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http;

use App\Platform\Http\Handler\HealthHandler;
use App\Platform\Http\RouteHandlerResolver;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteHandlerResolverTest extends TestCase
{
    #[DataProvider('provideRejectsMissingOrUnconstructedHandlersCases')]
    public function testRejectsMissingOrUnconstructedHandlers(mixed $handler): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A routed request must carry a request handler.');

        new RouteHandlerResolver()->handle(new ServerRequest('GET', '/health')->withAttribute('handler', $handler));
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideRejectsMissingOrUnconstructedHandlersCases(): iterable
    {
        yield 'missing' => [null];
        yield 'class name' => [HealthHandler::class];
    }
}
