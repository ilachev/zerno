<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use App\Platform\Http\Request;

final class Router implements RouterInterface
{
    /** @var array<string, array<string, RouteMapEntry>> */
    private array $routes = [];

    /** @var array<string, array<string, RouteMapEntry>> */
    private array $parameterizedRoutes = [];

    /** @var array<array-key, string> */
    private array $methods = [];

    /** @param list<RouteEntry> $routes */
    public function __construct(array $routes)
    {
        $this->buildRouteMap($routes);
    }

    public function dispatch(Request $request): RouteResult
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $result = $this->matchRoute($method, $path);
        if ($result !== null) {
            return $result;
        }

        $allowedMethods = [];
        foreach ($this->methods as $allowedMethod) {
            if ($allowedMethod !== $method && $this->matchRoute($allowedMethod, $path) !== null) {
                $allowedMethods[] = $allowedMethod;
            }
        }

        return $allowedMethods === []
            ? new RouteResult(status: RouteStatus::NOT_FOUND)
            : new RouteResult(status: RouteStatus::METHOD_NOT_ALLOWED, allowedMethods: $allowedMethods);
    }

    private function matchRoute(string $method, string $path): ?RouteResult
    {
        $route = $this->routes[$method][$path] ?? null;
        if ($route === null && str_ends_with($path, '/')) {
            $trimmedPath = rtrim($path, '/');
            if ($trimmedPath !== '') {
                $route = $this->routes[$method][$trimmedPath] ?? null;
            }
        }
        if ($route !== null) {
            return new RouteResult(RouteStatus::FOUND, $route->handler, $route->params);
        }

        foreach ($this->parameterizedRoutes[$method] ?? [] as $route) {
            if ($route->pattern === null || preg_match($route->pattern, $path, $matches) !== 1) {
                continue;
            }
            $params = [];
            foreach ($route->parameterNames as $index => $name) {
                $params[$name] = $matches[$index + 1];
            }

            return new RouteResult(
                RouteStatus::FOUND,
                $route->handler,
                $route->params->merge(RouteParameters::fromArray($params)),
            );
        }

        return null;
    }

    /** @param list<RouteEntry> $routes */
    private function buildRouteMap(array $routes): void
    {
        foreach ($routes as $route) {
            $entry = $this->compileRoute($route);
            if ($entry->pattern === null) {
                $this->routes[$route->method][$route->path] = $entry;
            } else {
                $this->parameterizedRoutes[$route->method][$route->path] = $entry;
            }
            $this->methods[$route->method] = $route->method;
        }
    }

    private function compileRoute(RouteEntry $route): RouteMapEntry
    {
        preg_match_all('/\{([^}]+)\}/', $route->path, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[0] === []) {
            return new RouteMapEntry($route->handler, RouteParameters::empty());
        }

        $pattern = '';
        $offset = 0;
        $parameterNames = [];
        foreach ($matches[0] as $index => [$placeholder, $position]) {
            $pattern .= preg_quote(substr($route->path, $offset, $position - $offset), '~') . '([^/]+)';
            $parameterNames[] = $matches[1][$index][0];
            $offset = $position + \strlen($placeholder);
        }
        $pattern .= preg_quote(substr($route->path, $offset), '~');

        return new RouteMapEntry(
            $route->handler,
            RouteParameters::empty(),
            '~\A' . $pattern . '\z~',
            $parameterNames,
        );
    }
}
