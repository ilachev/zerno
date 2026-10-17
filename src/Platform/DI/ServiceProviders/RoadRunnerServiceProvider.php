<?php

declare(strict_types=1);

namespace App\Platform\DI\ServiceProviders;

use App\Platform\DI\Container;
use App\Platform\DI\ServiceProvider;
use App\Platform\Logging\Logger;
use App\Platform\Logging\RoadRunnerLogger;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\WorkerInterface;

/** @implements ServiceProvider<object> */
final readonly class RoadRunnerServiceProvider implements ServiceProvider
{
    public function __construct(
        private int $chunkSize = 0,
    ) {
        if ($chunkSize < 0) {
            throw new \InvalidArgumentException('RoadRunner chunk size must not be negative');
        }
    }

    public function register(Container $container): void
    {
        $chunkSize = $this->chunkSize;
        $container->bind(Logger::class, RoadRunnerLogger::class);
        $container->set(Worker::class, static fn(): WorkerInterface => Worker::create());
        $container->set(
            PSR7Worker::class,
            static function (Container $container) use ($chunkSize): PSR7Worker {
                $worker = new PSR7Worker(
                    $container->get(Worker::class),
                    $container->get(ServerRequestFactoryInterface::class),
                    $container->get(StreamFactoryInterface::class),
                    $container->get(UploadedFileFactoryInterface::class),
                );
                $worker->chunkSize = $chunkSize;

                return $worker;
            },
        );
    }
}
