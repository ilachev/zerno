<?php

declare(strict_types=1);

namespace Project\HttpFixtures;

use App\Platform\Http\Request;
use App\Platform\Http\RequestContextAttributes;
use App\Platform\Http\RequestHandler;
use App\Platform\Http\Response;
use App\Platform\Http\UploadedFile;
use App\Platform\Routing\RouteResult;

final readonly class FixtureHandler implements RequestHandler
{
    public function handle(Request $request): Response
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
        if ($path === '/__wire/input') {
            $upload = null;
            $files = $request->getUploadedFiles()['files'] ?? [];
            if (\is_array($files) && isset($files[0])) {
                $file = $files[0];
                if (!$file instanceof UploadedFile) {
                    throw new \RuntimeException('Invalid uploaded file fixture');
                }
                $directory = getenv('WIRE_FILE_DIRECTORY');
                if ($directory === false || $directory === '') {
                    throw new \RuntimeException('Missing upload fixture directory');
                }
                $destination = $directory . '/upload-' . bin2hex(random_bytes(8));
                $stream = $file->getStream();

                try {
                    $file->moveTo($destination);
                    $upload = [
                        'name' => $file->getClientFilename(), 'type' => $file->getClientMediaType(),
                        'size' => $file->getSize(), 'sha256' => hash_file('sha256', $destination),
                        'closedAfterMove' => !$stream->isReadable(),
                    ];
                } finally {
                    $stream->close();
                    if (is_file($destination)) {
                        unlink($destination);
                    }
                }
            }

            return new Response(200, $headers, json_encode([
                'query' => $request->getQueryParams(), 'cookies' => $request->getCookieParams(),
                'parsed' => $request->getParsedBody(),
                'body' => $request->getHeaderLine('Content-Type') === 'application/json' ? (string) $request->getBody() : null,
                'upload' => $upload,
            ], JSON_THROW_ON_ERROR));
        }
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
