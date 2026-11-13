<?php

declare(strict_types=1);

namespace Zerno\Soak;

use App\Platform\Http\Request;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;

/** Test-only composition and bounded per-worker counters; no request is retained. */
final class Handler implements RequestHandler
{
    private int $requests = 0;

    public function __construct(private readonly RequestHandler $baseline, private readonly RequestHandler $wire) {}

    public function handle(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $handler = str_starts_with($path, '/__wire/') || $path === '/api/echo' ? $this->wire : $this->baseline;
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-Soak-Pid', (string) getmypid())
            ->withHeader('X-Soak-Requests', (string) ++$this->requests)
            ->withHeader('X-Soak-Php-Bytes', (string) memory_get_usage(true))
            ->withHeader('X-Soak-Php-Peak', (string) memory_get_peak_usage(true));
    }
}
