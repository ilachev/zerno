<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DI\ServiceProviders;

use App\Platform\DI\DIContainer;
use App\Platform\DI\ServiceProviders\CoreServiceProvider;
use App\Platform\DI\ServiceProviders\RoadRunnerServiceProvider;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\StreamWorkerInterface;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\WorkerInterface;

final class RoadRunnerServiceProviderTest extends TestCase
{
    public function testPreservesTheExistingBufferedDefault(): void
    {
        $transport = new RecordingStreamWorker();
        $worker = self::createWorker(new RoadRunnerServiceProvider(), $transport);

        $worker->respond(new Response(200, [], 'existing response'));

        self::assertSame(0, $worker->chunkSize);
        self::assertSame(1, $transport->frames);
        self::assertSame(0, $transport->streamModeCalls);
        self::assertSame(17, $transport->bytes);
        self::assertSame(hash('sha256', 'existing response'), $transport->digest());
    }

    public function testRejectsNegativeChunkSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RoadRunner chunk size must not be negative');

        new RoadRunnerServiceProvider(chunkSize: -1);
    }

    #[DataProvider('provideStreamsRealBodiesInBoundedChunksCases')]
    public function testStreamsRealBodiesInBoundedChunks(int $size): void
    {
        $transport = new RecordingStreamWorker();
        $worker = self::createWorker(new RoadRunnerServiceProvider(chunkSize: 8192), $transport);
        $resource = fopen('php://temp/maxmemory:8192', 'w+b');
        self::assertIsResource($resource);
        $body = Stream::create($resource);

        try {
            $remaining = $size;
            $block = str_repeat('0123456789abcdef', 512);
            $expectedHash = hash_init('sha256');

            while ($remaining > 0) {
                $chunk = substr($block, 0, min($remaining, \strlen($block)));
                if (fwrite($resource, $chunk) !== \strlen($chunk)) {
                    throw new \RuntimeException('Could not prepare the response stream');
                }
                hash_update($expectedHash, $chunk);
                $remaining -= \strlen($chunk);
            }

            $worker->respond(new Response(200, ['Content-Length' => (string) $size], $body));

            self::assertSame(8192, $worker->chunkSize);
            self::assertSame($size, $transport->bytes);
            self::assertSame(hash_final($expectedHash), $transport->digest());
            self::assertSame(max(1, (int) ceil($size / 8192)), $transport->frames);
            self::assertLessThanOrEqual(8192, $transport->largestChunk);
            self::assertSame(1, $transport->streamModeCalls);
            self::assertSame(1, $transport->completedStreams);
            self::assertTrue($transport->lastFrameEndedStream);
        } finally {
            $body->close();
        }
    }

    /** @return iterable<string, array{int}> */
    public static function provideStreamsRealBodiesInBoundedChunksCases(): iterable
    {
        yield 'empty' => [0];
        yield 'smaller than one chunk' => [8191];
        yield 'exact chunk' => [8192];
        yield 'large body with final partial chunk' => [16 * 1024 * 1024 + 17];
    }

    private static function createWorker(RoadRunnerServiceProvider $provider, WorkerInterface $transport): PSR7Worker
    {
        $container = new DIContainer();
        new CoreServiceProvider()->register($container);
        $provider->register($container);
        $container->set(Worker::class, static fn(): WorkerInterface => $transport);

        return $container->get(PSR7Worker::class);
    }
}

final class RecordingStreamWorker implements StreamWorkerInterface
{
    public int $bytes = 0;

    public int $frames = 0;

    public int $largestChunk = 0;

    public int $streamModeCalls = 0;

    public int $completedStreams = 0;

    public bool $lastFrameEndedStream = false;

    private readonly \HashContext $hash;

    public function __construct()
    {
        $this->hash = hash_init('sha256');
    }

    public function respond(Payload $payload): void
    {
        $size = \strlen($payload->body);
        $this->bytes += $size;
        ++$this->frames;
        $this->largestChunk = max($this->largestChunk, $size);
        $this->lastFrameEndedStream = $payload->eos;
        $this->completedStreams += (int) $payload->eos;
        hash_update($this->hash, $payload->body);
    }

    public function digest(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    public function withStreamMode(): static
    {
        ++$this->streamModeCalls;

        return $this;
    }

    public function waitPayload(): ?Payload
    {
        return null;
    }

    public function error(string $error): void
    {
        throw new \LogicException($error);
    }

    public function stop(): void {}

    public function hasPayload(?string $class = null): bool
    {
        return false;
    }

    public function getPayload(?string $class = null): ?Payload
    {
        return null;
    }
}
