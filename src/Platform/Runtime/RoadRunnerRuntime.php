<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Http\RequestHandler;
use Spiral\RoadRunner\WorkerInterface;

final readonly class RoadRunnerRuntime
{
    public function __construct(
        private RoadRunnerHttpWorker $worker,
        private RequestHandler $handler,
    ) {}

    public static function createWorker(WorkerInterface $transport, int $chunkSize = 0): RoadRunnerHttpWorker
    {
        return new RoadRunnerHttpWorker($transport, $chunkSize);
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
                $this->worker->respond($response, $request->getMethod());
            } finally {
                // Emission owns the body; a handler must provide a fresh stream for every request.
                $body->close();
            }
        }
    }
}
