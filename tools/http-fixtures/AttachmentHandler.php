<?php

declare(strict_types=1);

namespace Project\HttpFixtures;

use App\Platform\Http\AttachmentResponse;
use App\Platform\Http\Handler\HandlerInterface;
use App\Platform\Routing\RouteResult;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AttachmentHandler implements HandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getAttribute(RouteResult::class);
        if (!$route instanceof RouteResult) {
            throw new \LogicException('Attachment fixture did not receive route parameters');
        }
        $size = $route->getParams()['size'] ?? '';
        if (!\in_array($size, array_map(strval(...), AttachmentFiles::SIZES), true)) {
            throw new \InvalidArgumentException('Unknown attachment fixture size');
        }
        $directory = getenv('WIRE_FILE_DIRECTORY');
        if ($directory === false || $directory === '') {
            throw new \RuntimeException('Attachment fixture directory must be explicitly selected');
        }
        $body = fopen($directory . '/' . $size . '.bin', 'rb');
        if ($body === false) {
            throw new \RuntimeException('Cannot open the private attachment fixture');
        }

        return AttachmentResponse::create(Stream::create($body), 'Отчёт "' . $size . '".bin', (int) $size, fallbackFilename: 'fixture-' . $size . '.bin')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Fixture-Method', $request->getMethod())
            ->withHeader('X-Fixture-Pid', (string) getmypid())
            ->withHeader('X-Fixture-Memory-Limit', (string) \ini_get('memory_limit'))
            ->withHeader('X-Fixture-Memory-Usage', (string) memory_get_usage(true));
    }
}
