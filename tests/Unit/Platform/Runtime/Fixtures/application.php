<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\Http\Middleware;
use App\Platform\Http\Middleware\Pipeline;
use App\Platform\Http\RequestHandler;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\Unit\Platform\Runtime\Fixtures\RequestTrace;

return static function (Container $container): void {
    $trace = new RequestTrace();
    $container->set(RequestTrace::class, static fn(): RequestTrace => $trace);

    $container->set(RequestHandler::class, static function () use ($trace): RequestHandler {
        $handler = new readonly class ($trace) implements RequestHandler {
            public function __construct(private RequestTrace $trace) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $value = $request->getHeaderLine('X-Test-Value');
                $this->trace->events[] = 'handler:' . $value;
                $attribute = $request->getAttribute('trace-value');
                if (!is_string($attribute)) {
                    throw new LogicException('Expected middleware request attribute.');
                }

                return new Response(202, ['X-Handler' => $value], $attribute);
            }
        };

        $middleware = static fn(string $name): Middleware => new readonly class ($name, $trace) implements Middleware {
            public function __construct(private string $name, private RequestTrace $trace) {}

            public function process(ServerRequestInterface $request, RequestHandler $handler): ResponseInterface
            {
                $value = $request->getHeaderLine('X-Test-Value');
                $this->trace->events[] = $this->name . ':enter:' . $value;
                $attribute = $request->getAttribute('trace-value', $value);
                if (!is_string($attribute)) {
                    throw new LogicException('Expected a string request attribute.');
                }

                $response = $handler->handle($request->withAttribute('trace-value', $attribute . ':' . $this->name));
                $this->trace->events[] = $this->name . ':exit:' . $value;

                return $response->withAddedHeader('X-Middleware', $this->name);
            }
        };

        return new Pipeline($handler, [$middleware('outer'), $middleware('inner')]);
    });
};
