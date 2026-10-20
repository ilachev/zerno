<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Http\RequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;

final readonly class RoadRunnerRuntime
{
    public function __construct(
        private PSR7WorkerInterface $worker,
        private RequestHandler $handler,
    ) {}

    public static function createWorker(WorkerInterface $transport, int $chunkSize = 0): PSR7Worker
    {
        if ($chunkSize < 0) {
            throw new \InvalidArgumentException('RoadRunner chunk size must not be negative');
        }
        $factory = new Psr17Factory();
        $worker = new PSR7Worker($transport, $factory, $factory, $factory);
        $worker->chunkSize = $chunkSize;

        return $worker;
    }

    public function run(): void
    {
        while (true) {
            $request = $this->worker->waitRequest();
            if ($request === null) {
                break;
            }

            $response = $this->handler->handle($request);
            $body = $response->getBody();

            try {
                $status = $response->getStatusCode();
                if ($request->getMethod() === 'HEAD' || $status < 200 || $status === 204 || $status === 304) {
                    $response = $response->withBody(Stream::create());
                    if ($status < 200 || $status === 204) {
                        $response = $response->withoutHeader('Content-Length');
                    }
                }
                $this->worker->respond($response);
            } finally {
                // Emission owns the body; a handler must provide a fresh stream for every request.
                $body->close();
                if ($response->getBody() !== $body) {
                    $response->getBody()->close();
                }
            }
        }
    }
}
