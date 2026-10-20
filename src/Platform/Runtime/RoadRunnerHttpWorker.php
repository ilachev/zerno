<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Http\Body;
use App\Platform\Http\Request;
use App\Platform\Http\Response;
use App\Platform\Http\UploadedFile;
use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\WorkerInterface;

/** Maps the native RoadRunner HTTP transport to the core's concrete HTTP types. */
final readonly class RoadRunnerHttpWorker
{
    private HttpWorker $http;

    public function __construct(WorkerInterface $transport, public int $chunkSize = 0)
    {
        if ($chunkSize < 0) {
            throw new \InvalidArgumentException('RoadRunner chunk size must not be negative');
        }
        $this->http = new HttpWorker($transport);
    }

    public function waitRequest(): ?Request
    {
        $incoming = $this->http->waitRequest();
        if ($incoming === null) {
            return null;
        }
        $protocol = match ($incoming->protocol) {
            'HTTP/1.0' => '1.0',
            'HTTP/2', 'HTTP/2.0' => '2',
            'HTTP/3', 'HTTP/3.0' => '3',
            default => '1.1',
        };
        $headers = array_map(array_values(...), $incoming->headers);
        $request = new Request(
            $incoming->method,
            $incoming->uri,
            $headers,
            $incoming->body,
            $protocol,
            ['REMOTE_ADDR' => $incoming->getRemoteAddr(), 'REQUEST_METHOD' => $incoming->method, 'REQUEST_URI' => $incoming->uri],
        )->withQueryParams($incoming->query)
            ->withCookieParams($incoming->cookies)
            ->withUploadedFiles($this->uploads($incoming->uploads))
            ->withParsedBody($incoming->getParsedBody());
        foreach ($incoming->attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    public function respond(Response $response, string $method = 'GET'): void
    {
        $status = $response->getStatusCode();
        $headers = $response->getHeaders();
        if ($status < 200 || $status === 204) {
            foreach (array_keys($headers) as $name) {
                if (strcasecmp($name, 'Content-Length') === 0) {
                    unset($headers[$name]);
                }
            }
        }
        if ($method === 'HEAD' || $status < 200 || $status === 204 || $status === 304) {
            $this->http->respond($status, '', $headers);

            return;
        }
        $readFailure = null;
        $this->http->respond(
            $status,
            $this->chunkSize === 0 ? (string) $response->getBody() : $this->chunks($response->getBody(), $readFailure),
            $headers,
        );
        // The native transport completes a stream after generator failures; retain the I/O error.
        if ($readFailure !== null) {
            throw $readFailure;
        }
    }

    /** @return \Generator<int, string, mixed, string> */
    private function chunks(Body $body, ?\Throwable &$readFailure): \Generator
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        // One chunk of lookahead marks the final frame without buffering the full body.
        $chunk = $this->readChunk($body, $readFailure);

        while (!$body->eof()) {
            $next = $this->readChunk($body, $readFailure);
            if ($next === '' && !$body->eof()) {
                $readFailure = new \RuntimeException('Response stream returned no data before EOF');

                throw $readFailure;
            }
            if ($next === '' && $body->eof()) {
                break;
            }
            yield $chunk;
            $chunk = $next;
        }

        return $chunk;
    }

    private function readChunk(Body $body, ?\Throwable &$failure): string
    {
        try {
            return $body->read($this->chunkSize);
        } catch (\Throwable $exception) {
            $failure = $exception;

            throw $exception;
        }
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function uploads(array $files): array
    {
        $uploads = [];
        foreach ($files as $index => $file) {
            if (!\is_array($file)) {
                throw new \InvalidArgumentException('Invalid RoadRunner upload');
            }
            if (!isset($file['name'])) {
                $uploads[$index] = $this->uploads($file);

                continue;
            }
            if (!\is_string($file['name']) || !\is_string($file['tmpName'] ?? null)
                || !\is_string($file['mime'] ?? null) || !\is_int($file['size'] ?? null) || !\is_int($file['error'] ?? null)) {
                throw new \InvalidArgumentException('Invalid RoadRunner upload metadata');
            }
            $uploads[$index] = new UploadedFile($file['tmpName'], $file['size'], $file['error'], $file['name'], $file['mime'], sapiUpload: false);
        }

        return $uploads;
    }
}
