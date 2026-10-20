<?php

declare(strict_types=1);

namespace Project\HttpFixtures;

use App\Platform\Http\RequestContextAttributes;
use App\Platform\Http\RequestHandler;
use App\Platform\Routing\RouteResult;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class FixtureHandler implements RequestHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/__wire/failure') {
            throw new \RuntimeException('wire-private-exception-details');
        }
        $headers = [
            'Content-Type' => 'application/json',
            'X-Fixture-Method' => $request->getMethod(),
            'X-Fixture-Pid' => (string) getmypid(),
        ];
        $status = match ($path) {
            '/__wire/status/204' => 204,
            '/__wire/status/304' => 304,
            default => 200,
        };
        if ($status !== 200) {
            return new Response($status, $headers, 'this body must not reach the wire');
        }
        if ($path === '/__wire/headers') {
            $headers['X-Fixture-Multi'] = ['first', 'second'];
            $headers['Set-Cookie'] = ['first=one; Path=/; HttpOnly', 'second=two; Path=/; HttpOnly'];
        }
        $route = $request->getAttribute(RouteResult::class);
        if (!$route instanceof RouteResult) {
            throw new \LogicException('Fixture did not receive route parameters');
        }

        return new Response(200, $headers, json_encode([
            'path' => $path,
            'query' => $request->getUri()->getQuery(),
            'queryParams' => $request->getQueryParams(),
            'params' => $route->getParams(),
            'requestId' => RequestContextAttributes::require($request)->requestId,
        ], JSON_THROW_ON_ERROR));
    }
}
