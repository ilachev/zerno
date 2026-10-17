<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Routing;

use App\Platform\Routing\RouteParameters;
use App\Platform\Routing\RouteResult;
use App\Platform\Routing\RouteStatus;
use PHPUnit\Framework\TestCase;

final class RouteResultTest extends TestCase
{
    public function testIsFoundReturnsTrue(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::FOUND,
            'App\Handler',
            ['id' => '1'],
        );

        self::assertTrue($routeResult->isFound());
    }

    public function testIsFoundReturnsFalse(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::NOT_FOUND,
        );

        self::assertFalse($routeResult->isFound());
    }

    public function testGetHandlerReturnsHandler(): void
    {
        $handler = 'App\Handler';
        $routeResult = new RouteResult(
            RouteStatus::FOUND,
            $handler,
            [],
        );

        self::assertEquals($handler, $routeResult->getHandler());
    }

    public function testGetHandlerThrowsExceptionWhenNotFound(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::NOT_FOUND,
        );

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Route not found');

        $routeResult->getHandler();
    }

    public function testGetParamsReturnsParams(): void
    {
        $params = ['id' => '1'];
        $routeResult = new RouteResult(
            RouteStatus::FOUND,
            'App\Handler',
            $params,
        );

        self::assertEquals($params, $routeResult->getParams());
    }

    public function testGetRouteParametersReturnsTypedParameters(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::FOUND,
            'App\Handler',
            ['id' => '1'],
        );

        self::assertEquals(
            new RouteParameters(['id' => '1']),
            $routeResult->getRouteParameters(),
        );
    }

    public function testGetParamsReturnsEmptyArrayWhenNoParams(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::FOUND,
            'App\Handler',
        );

        self::assertEquals([], $routeResult->getParams());
    }

    public function testGetStatusCodeReturns200WhenFound(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::FOUND,
            'App\Handler',
            [],
        );

        self::assertEquals(200, $routeResult->getStatusCode());
    }

    public function testGetStatusCodeReturns404WhenNotFound(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::NOT_FOUND,
        );

        self::assertEquals(404, $routeResult->getStatusCode());
    }

    public function testMethodNotAllowedCarriesMethodsWithoutExposingAHandler(): void
    {
        $routeResult = new RouteResult(
            RouteStatus::METHOD_NOT_ALLOWED,
            null,
            [],
            ['GET', 'HEAD'],
        );

        self::assertFalse($routeResult->isFound());
        self::assertSame(405, $routeResult->getStatusCode());
        self::assertSame(['GET', 'HEAD'], $routeResult->getAllowedMethods());
        $this->expectException(\RuntimeException::class);
        $routeResult->getHandler();
    }

    public function testExistingConstructorArgumentsKeepEmptyAllowedMethods(): void
    {
        $routeResult = new RouteResult(RouteStatus::FOUND, 'App\Handler', ['id' => '1']);

        self::assertSame('App\Handler', $routeResult->getHandler());
        self::assertSame(['id' => '1'], $routeResult->getParams());
        self::assertSame([], $routeResult->getAllowedMethods());
        self::assertSame([], new RouteResult(RouteStatus::NOT_FOUND)->getAllowedMethods());
    }
}
