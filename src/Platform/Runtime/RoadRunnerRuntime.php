<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Http\RequestHandler;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;

final readonly class RoadRunnerRuntime
{
    public function __construct(
        private PSR7WorkerInterface $worker,
        private RequestHandler $handler,
    ) {}

    public function run(): void
    {
        while (true) {
            $request = $this->worker->waitRequest();
            if ($request === null) {
                break;
            }

            $response = $this->handler->handle($request);
            $this->worker->respond($response);
        }
    }
}
