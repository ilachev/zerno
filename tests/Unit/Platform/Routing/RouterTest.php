<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Routing;

use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    /** @var array<string, RequestHandler> */
    private array $handlers = [];

    private function handler(string $name): RequestHandler
    {
        return $this->handlers[$name] ??= new readonly class ($name) implements RequestHandler {
            public function __construct(private string $name) {}

            public function handle(Request $request): Response
            {
                return new Response(200, [], $this->name);
            }
        };
    }

    private Router $router;

    protected function setUp(): void
    {
        $this->router = $this->createRouter([
            new RouteEntry('GET', '/api/test', $this->handler('TestHandler')),
            new RouteEntry('POST', '/api/test', $this->handler('TestPostHandler')),
            new RouteEntry('GET', '/api/users/{id}', $this->handler('UserHandler')),
            new RouteEntry('GET', '/api/posts/{slug}', $this->handler('PostHandler')),
        ]);
    }

    public function testDispatchExactMatch(): void
    {
        $result = $this->router->dispatch(new Request('GET', '/api/test'));

        self::assertTrue($result->isFound());
        self::assertSame($this->handler('TestHandler'), $result->getHandler());
        self::assertSame([], $result->getParams());
    }

    public function testDispatchWithTrailingSlash(): void
    {
        foreach (['/api/test/', '/api/test///'] as $path) {
            $result = $this->router->dispatch(new Request('GET', $path));

            self::assertTrue($result->isFound());
            self::assertSame($this->handler('TestHandler'), $result->getHandler());
        }
    }

    public function testDispatchMethodNotAllowed(): void
    {
        $result = $this->router->dispatch(new Request('PUT', '/api/test'));

        self::assertSame(405, $result->getStatusCode());
        self::assertSame(['GET', 'POST'], $result->getAllowedMethods());
        $this->expectException(\RuntimeException::class);
        $result->getHandler();
    }

    public function testDispatchNotFound(): void
    {
        $result = $this->router->dispatch(new Request('GET', '/api/not-found'));

        self::assertSame(404, $result->getStatusCode());
        self::assertSame([], $result->getAllowedMethods());
    }

    public function testDispatchWithParameters(): void
    {
        $result = $this->router->dispatch(new Request('GET', '/api/users/123'));

        self::assertTrue($result->isFound());
        self::assertSame($this->handler('UserHandler'), $result->getHandler());
        self::assertSame(['id' => '123'], $result->getParams());
    }

    public function testDispatchWithMultipleParameters(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/api/users/{id}/posts/{postId}', $this->handler('UserPostHandler')),
        ]);
        $result = $router->dispatch(new Request('GET', '/api/users/123/posts/456'));

        self::assertTrue($result->isFound());
        self::assertSame($this->handler('UserPostHandler'), $result->getHandler());
        self::assertSame(['id' => '123', 'postId' => '456'], $result->getParams());
    }

    public function testStaticRouteForAnotherMethodDoesNotBlockParameterizedRoute(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/current', $this->handler('CurrentItemHandler')),
            new RouteEntry('POST', '/items/{id}', $this->handler('SaveItemHandler')),
        ]);
        $result = $router->dispatch(new Request('POST', '/items/current'));

        self::assertSame(200, $result->getStatusCode());
        self::assertSame($this->handler('SaveItemHandler'), $result->getHandler());
        self::assertSame(['id' => 'current'], $result->getParams());
    }

    public function testStaticRouteTakesPrecedenceForTheSameMethod(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/{id}', $this->handler('ItemHandler')),
            new RouteEntry('GET', '/items/current', $this->handler('CurrentItemHandler')),
        ]);
        $result = $router->dispatch(new Request('GET', '/items/current'));

        self::assertSame($this->handler('CurrentItemHandler'), $result->getHandler());
        self::assertSame([], $result->getParams());
    }

    public function testParameterizedPathWithWrongMethodReturns405(): void
    {
        foreach (['POST', 'HEAD', 'OPTIONS', 'REPORT'] as $method) {
            $result = $this->router->dispatch(new Request($method, '/api/users/123'));

            self::assertSame(405, $result->getStatusCode());
            self::assertSame(['GET'], $result->getAllowedMethods());
        }
    }

    public function testAllowedMethodsCombineAllMatchingPathsWithoutDuplicates(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/new', $this->handler('NewItemHandler')),
            new RouteEntry('POST', '/items/{id}', $this->handler('SaveItemHandler')),
            new RouteEntry('HEAD', '/items/new', $this->handler('HeadItemHandler')),
            new RouteEntry('GET', '/items/{name}', $this->handler('ItemHandler')),
            new RouteEntry('POST', '/items/{id}', $this->handler('UpdatedSaveItemHandler')),
        ]);
        $result = $router->dispatch(new Request('DELETE', '/items/new'));

        self::assertSame(405, $result->getStatusCode());
        self::assertSame(['GET', 'POST', 'HEAD'], $result->getAllowedMethods());
        self::assertSame(
            $this->handler('UpdatedSaveItemHandler'),
            $router->dispatch(new Request('POST', '/items/new'))->getHandler(),
        );
    }

    public function testExplicitHeadRouteIsDispatchedWithoutGetFallback(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/health', $this->handler('GetHealthHandler')),
            new RouteEntry('HEAD', '/health', $this->handler('HeadHealthHandler')),
        ]);

        self::assertSame($this->handler('HeadHealthHandler'), $router->dispatch(new Request('HEAD', '/health'))->getHandler());
        $result = $router->dispatch(new Request('POST', '/health'));
        self::assertSame(['GET', 'HEAD'], $result->getAllowedMethods());
        self::assertSame(405, $this->router->dispatch(new Request('HEAD', '/api/test'))->getStatusCode());
    }

    public function testParameterizedRouteOrderAndDuplicateReplacementArePreserved(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/{id}', $this->handler('OriginalItemHandler')),
            new RouteEntry('GET', '/items/{slug}', $this->handler('SlugHandler')),
            new RouteEntry('GET', '/items/{id}', $this->handler('ReplacementItemHandler')),
        ]);
        $result = $router->dispatch(new Request('GET', '/items/42'));

        self::assertSame($this->handler('ReplacementItemHandler'), $result->getHandler());
        self::assertSame(['id' => '42'], $result->getParams());
    }

    public function testDuplicateStaticRoutesReplaceHandlerWithoutDuplicatingAllowedMethod(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/health', $this->handler('OriginalHealthHandler')),
            new RouteEntry('GET', '/health', $this->handler('ReplacementHealthHandler')),
        ]);

        self::assertSame($this->handler('ReplacementHealthHandler'), $router->dispatch(new Request('GET', '/health'))->getHandler());
        self::assertSame(['GET'], $router->dispatch(new Request('POST', '/health'))->getAllowedMethods());
    }

    #[DataProvider('provideLiteralPatternCharactersDoNotBecomeRegexCases')]
    public function testLiteralPatternCharactersDoNotBecomeRegex(string $prefix, string $differentPrefix): void
    {
        $router = $this->createRouter([new RouteEntry('GET', $prefix . '/{id}', $this->handler('FileHandler'))]);
        $result = $router->dispatch(new Request('GET', $prefix . '/42'));

        self::assertSame($this->handler('FileHandler'), $result->getHandler());
        self::assertSame(['id' => '42'], $result->getParams());
        self::assertSame(404, $router->dispatch(new Request('GET', $differentPrefix . '/42'))->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideLiteralPatternCharactersDoNotBecomeRegexCases(): iterable
    {
        yield 'dot' => ['/files/release.v1', '/files/releaseXv1'];
        yield 'plus' => ['/files/a+b', '/files/aab'];
        yield 'parentheses' => ['/files/(draft)', '/files/draft'];
        yield 'dollar' => ['/files/cost$value', '/files/costvalue'];
        yield 'delimiter' => ['/files/a~b', '/files/ab'];
    }

    public function testLiteralSuffixAfterParameterIsEscaped(): void
    {
        $router = $this->createRouter([new RouteEntry('GET', '/files/{name}.json', $this->handler('JsonFileHandler'))]);
        $result = $router->dispatch(new Request('GET', '/files/report.json'));

        self::assertSame($this->handler('JsonFileHandler'), $result->getHandler());
        self::assertSame(['name' => 'report'], $result->getParams());
        self::assertSame(404, $router->dispatch(new Request('GET', '/files/reportXjson'))->getStatusCode());
    }

    public function testQueryDoesNotParticipateInPathMatching(): void
    {
        $result = $this->router->dispatch(new Request('GET', '/api/users/123?next=/posts/456'));

        self::assertSame($this->handler('UserHandler'), $result->getHandler());
        self::assertSame(['id' => '123'], $result->getParams());
        self::assertSame($this->handler('TestHandler'), $this->router->dispatch(new Request('GET', '/api/test?key=value'))->getHandler());
    }

    public function testRootAndExplicitTrailingSlashRoutesRemainDistinct(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/', $this->handler('RootHandler')),
            new RouteEntry('GET', '/items', $this->handler('ItemsHandler')),
            new RouteEntry('POST', '/items/', $this->handler('SaveItemsHandler')),
        ]);

        self::assertSame($this->handler('RootHandler'), $router->dispatch(new Request('GET', '/'))->getHandler());
        self::assertSame($this->handler('SaveItemsHandler'), $router->dispatch(new Request('POST', '/items/'))->getHandler());
        self::assertSame(405, $router->dispatch(new Request('POST', '/items'))->getStatusCode());
        self::assertSame(['GET', 'POST'], $router->dispatch(new Request('DELETE', '/items/'))->getAllowedMethods());
    }

    public function testParameterizedTrailingSlashMatchesOnlyItsDeclaredPattern(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/{id}', $this->handler('ItemHandler')),
            new RouteEntry('POST', '/items/{id}/', $this->handler('SaveItemHandler')),
        ]);

        self::assertSame(['POST'], $router->dispatch(new Request('GET', '/items/42/'))->getAllowedMethods());
        self::assertSame(['GET'], $router->dispatch(new Request('POST', '/items/42'))->getAllowedMethods());
        self::assertSame(['id' => '42'], $router->dispatch(new Request('POST', '/items/42/'))->getParams());
        self::assertSame(404, $this->router->dispatch(new Request('GET', '/api/users/123/'))->getStatusCode());
        self::assertSame(404, $this->router->dispatch(new Request('POST', '/api/users/123/'))->getStatusCode());
    }

    #[DataProvider('provideExtensionMethodsRemainStringsCases')]
    public function testExtensionMethodsRemainStrings(string $method): void
    {
        $router = $this->createRouter([
            new RouteEntry($method, '/custom', $this->handler('CustomHandler')),
            new RouteEntry($method, '/custom/{id}', $this->handler('CustomHandler')),
        ]);

        foreach (['/custom', '/custom/42'] as $path) {
            self::assertSame($this->handler('CustomHandler'), $router->dispatch(new Request($method, $path))->getHandler());
            $result = $router->dispatch(new Request('GET', $path));
            self::assertSame(405, $result->getStatusCode());
            self::assertSame([$method], $result->getAllowedMethods());
        }
        self::assertSame(404, $router->dispatch(new Request('GET', '/missing'))->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function provideExtensionMethodsRemainStringsCases(): iterable
    {
        yield 'named' => ['REPORT'];
        yield 'numeric' => ['123'];
        yield 'zero' => ['0'];
    }

    /** @param list<RouteEntry> $routes */
    private function createRouter(array $routes): Router
    {
        return new Router($routes);
    }
}
