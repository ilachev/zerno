<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Routing;

use App\Platform\Routing\RouteDefinition;
use App\Platform\Routing\RouteEntry;
use App\Platform\Routing\Router;
use App\Platform\Routing\StaticRouteProvider;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = $this->createRouter([
            new RouteEntry('GET', '/api/test', 'TestHandler'),
            new RouteEntry('POST', '/api/test', 'TestPostHandler'),
            new RouteEntry('GET', '/api/users/{id}', 'UserHandler'),
            new RouteEntry('GET', '/api/posts/{slug}', 'PostHandler'),
        ]);
    }

    public function testDispatchExactMatch(): void
    {
        $result = $this->router->dispatch(new ServerRequest('GET', '/api/test'));

        self::assertTrue($result->isFound());
        self::assertSame('TestHandler', $result->getHandler());
        self::assertSame([], $result->getParams());
    }

    public function testDispatchWithTrailingSlash(): void
    {
        foreach (['/api/test/', '/api/test///'] as $path) {
            $result = $this->router->dispatch(new ServerRequest('GET', $path));

            self::assertTrue($result->isFound());
            self::assertSame('TestHandler', $result->getHandler());
        }
    }

    public function testDispatchMethodNotAllowed(): void
    {
        $result = $this->router->dispatch(new ServerRequest('PUT', '/api/test'));

        self::assertSame(405, $result->getStatusCode());
        self::assertSame(['GET', 'POST'], $result->getAllowedMethods());
        $this->expectException(\RuntimeException::class);
        $result->getHandler();
    }

    public function testDispatchNotFound(): void
    {
        $result = $this->router->dispatch(new ServerRequest('GET', '/api/not-found'));

        self::assertSame(404, $result->getStatusCode());
        self::assertSame([], $result->getAllowedMethods());
    }

    public function testDispatchWithParameters(): void
    {
        $result = $this->router->dispatch(new ServerRequest('GET', '/api/users/123'));

        self::assertTrue($result->isFound());
        self::assertSame('UserHandler', $result->getHandler());
        self::assertSame(['id' => '123'], $result->getParams());
    }

    public function testDispatchWithMultipleParameters(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/api/users/{id}/posts/{postId}', 'UserPostHandler'),
        ]);
        $result = $router->dispatch(new ServerRequest('GET', '/api/users/123/posts/456'));

        self::assertTrue($result->isFound());
        self::assertSame('UserPostHandler', $result->getHandler());
        self::assertSame(['id' => '123', 'postId' => '456'], $result->getParams());
    }

    public function testStaticRouteForAnotherMethodDoesNotBlockParameterizedRoute(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/current', 'CurrentItemHandler'),
            new RouteEntry('POST', '/items/{id}', 'SaveItemHandler'),
        ]);
        $result = $router->dispatch(new ServerRequest('POST', '/items/current'));

        self::assertSame(200, $result->getStatusCode());
        self::assertSame('SaveItemHandler', $result->getHandler());
        self::assertSame(['id' => 'current'], $result->getParams());
    }

    public function testStaticRouteTakesPrecedenceForTheSameMethod(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/{id}', 'ItemHandler'),
            new RouteEntry('GET', '/items/current', 'CurrentItemHandler'),
        ]);
        $result = $router->dispatch(new ServerRequest('GET', '/items/current'));

        self::assertSame('CurrentItemHandler', $result->getHandler());
        self::assertSame([], $result->getParams());
    }

    public function testParameterizedPathWithWrongMethodReturns405(): void
    {
        foreach (['POST', 'HEAD', 'OPTIONS', 'REPORT'] as $method) {
            $result = $this->router->dispatch(new ServerRequest($method, '/api/users/123'));

            self::assertSame(405, $result->getStatusCode());
            self::assertSame(['GET'], $result->getAllowedMethods());
        }
    }

    public function testAllowedMethodsCombineAllMatchingPathsWithoutDuplicates(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/new', 'NewItemHandler'),
            new RouteEntry('POST', '/items/{id}', 'SaveItemHandler'),
            new RouteEntry('HEAD', '/items/new', 'HeadItemHandler'),
            new RouteEntry('GET', '/items/{name}', 'ItemHandler'),
            new RouteEntry('POST', '/items/{id}', 'UpdatedSaveItemHandler'),
        ]);
        $result = $router->dispatch(new ServerRequest('DELETE', '/items/new'));

        self::assertSame(405, $result->getStatusCode());
        self::assertSame(['GET', 'POST', 'HEAD'], $result->getAllowedMethods());
        self::assertSame(
            'UpdatedSaveItemHandler',
            $router->dispatch(new ServerRequest('POST', '/items/new'))->getHandler(),
        );
    }

    public function testExplicitHeadRouteIsDispatchedWithoutGetFallback(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/health', 'GetHealthHandler'),
            new RouteEntry('HEAD', '/health', 'HeadHealthHandler'),
        ]);

        self::assertSame('HeadHealthHandler', $router->dispatch(new ServerRequest('HEAD', '/health'))->getHandler());
        $result = $router->dispatch(new ServerRequest('POST', '/health'));
        self::assertSame(['GET', 'HEAD'], $result->getAllowedMethods());
        self::assertSame(405, $this->router->dispatch(new ServerRequest('HEAD', '/api/test'))->getStatusCode());
    }

    public function testParameterizedRouteOrderAndDuplicateReplacementArePreserved(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/{id}', 'OriginalItemHandler'),
            new RouteEntry('GET', '/items/{slug}', 'SlugHandler'),
            new RouteEntry('GET', '/items/{id}', 'ReplacementItemHandler'),
        ]);
        $result = $router->dispatch(new ServerRequest('GET', '/items/42'));

        self::assertSame('ReplacementItemHandler', $result->getHandler());
        self::assertSame(['id' => '42'], $result->getParams());
    }

    public function testDuplicateStaticRoutesReplaceHandlerWithoutDuplicatingAllowedMethod(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/health', 'OriginalHealthHandler'),
            new RouteEntry('GET', '/health', 'ReplacementHealthHandler'),
        ]);

        self::assertSame('ReplacementHealthHandler', $router->dispatch(new ServerRequest('GET', '/health'))->getHandler());
        self::assertSame(['GET'], $router->dispatch(new ServerRequest('POST', '/health'))->getAllowedMethods());
    }

    #[DataProvider('provideLiteralPatternCharactersDoNotBecomeRegexCases')]
    public function testLiteralPatternCharactersDoNotBecomeRegex(string $prefix, string $differentPrefix): void
    {
        $router = $this->createRouter([new RouteEntry('GET', $prefix . '/{id}', 'FileHandler')]);
        $result = $router->dispatch(new ServerRequest('GET', $prefix . '/42'));

        self::assertSame('FileHandler', $result->getHandler());
        self::assertSame(['id' => '42'], $result->getParams());
        self::assertSame(404, $router->dispatch(new ServerRequest('GET', $differentPrefix . '/42'))->getStatusCode());
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
        $router = $this->createRouter([new RouteEntry('GET', '/files/{name}.json', 'JsonFileHandler')]);
        $result = $router->dispatch(new ServerRequest('GET', '/files/report.json'));

        self::assertSame('JsonFileHandler', $result->getHandler());
        self::assertSame(['name' => 'report'], $result->getParams());
        self::assertSame(404, $router->dispatch(new ServerRequest('GET', '/files/reportXjson'))->getStatusCode());
    }

    public function testQueryDoesNotParticipateInPathMatching(): void
    {
        $result = $this->router->dispatch(new ServerRequest('GET', '/api/users/123?next=/posts/456'));

        self::assertSame('UserHandler', $result->getHandler());
        self::assertSame(['id' => '123'], $result->getParams());
        self::assertSame('TestHandler', $this->router->dispatch(new ServerRequest('GET', '/api/test?key=value'))->getHandler());
    }

    public function testRootAndExplicitTrailingSlashRoutesRemainDistinct(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/', 'RootHandler'),
            new RouteEntry('GET', '/items', 'ItemsHandler'),
            new RouteEntry('POST', '/items/', 'SaveItemsHandler'),
        ]);

        self::assertSame('RootHandler', $router->dispatch(new ServerRequest('GET', '/'))->getHandler());
        self::assertSame('SaveItemsHandler', $router->dispatch(new ServerRequest('POST', '/items/'))->getHandler());
        self::assertSame(405, $router->dispatch(new ServerRequest('POST', '/items'))->getStatusCode());
        self::assertSame(['GET', 'POST'], $router->dispatch(new ServerRequest('DELETE', '/items/'))->getAllowedMethods());
    }

    public function testParameterizedTrailingSlashMatchesOnlyItsDeclaredPattern(): void
    {
        $router = $this->createRouter([
            new RouteEntry('GET', '/items/{id}', 'ItemHandler'),
            new RouteEntry('POST', '/items/{id}/', 'SaveItemHandler'),
        ]);

        self::assertSame(['POST'], $router->dispatch(new ServerRequest('GET', '/items/42/'))->getAllowedMethods());
        self::assertSame(['GET'], $router->dispatch(new ServerRequest('POST', '/items/42'))->getAllowedMethods());
        self::assertSame(['id' => '42'], $router->dispatch(new ServerRequest('POST', '/items/42/'))->getParams());
        self::assertSame(404, $this->router->dispatch(new ServerRequest('GET', '/api/users/123/'))->getStatusCode());
        self::assertSame(404, $this->router->dispatch(new ServerRequest('POST', '/api/users/123/'))->getStatusCode());
    }

    #[DataProvider('provideExtensionMethodsRemainStringsCases')]
    public function testExtensionMethodsRemainStrings(string $method): void
    {
        $router = $this->createRouter([
            new RouteEntry($method, '/custom', 'CustomHandler'),
            new RouteEntry($method, '/custom/{id}', 'CustomHandler'),
        ]);

        foreach (['/custom', '/custom/42'] as $path) {
            self::assertSame('CustomHandler', $router->dispatch(new ServerRequest($method, $path))->getHandler());
            $result = $router->dispatch(new ServerRequest('GET', $path));
            self::assertSame(405, $result->getStatusCode());
            self::assertSame([$method], $result->getAllowedMethods());
        }
        self::assertSame(404, $router->dispatch(new ServerRequest('GET', '/missing'))->getStatusCode());
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
        return new Router(new RouteDefinition(new StaticRouteProvider($routes)));
    }
}
