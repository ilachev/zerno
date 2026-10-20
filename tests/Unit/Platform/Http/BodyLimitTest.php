<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http;

use App\Platform\Http\Body;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ObservedStream;

final class BodyLimitTest extends TestCase
{
    #[DataProvider('provideReadsExactBoundariesForStringsAndResourcesCases')]
    public function testReadsExactBoundariesForStringsAndResources(int $size, int $limit): void
    {
        $content = str_repeat('a', $size);
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $content);
        rewind($resource);
        foreach ([Body::create($content), Body::create($resource)] as $body) {
            try {
                if ($size > $limit) {
                    try {
                        $body->readLimited($limit);
                        self::fail('An oversized body must not return partial data');
                    } catch (\LengthException) {
                        self::assertSame($limit + 1, $body->tell());
                    }
                } else {
                    self::assertSame($content, $body->readLimited($limit));
                    self::assertSame($size, $body->tell());
                    self::assertTrue($body->eof());
                }
                self::assertTrue($body->isReadable());
            } finally {
                $body->close();
            }
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideReadsExactBoundariesForStringsAndResourcesCases(): iterable
    {
        yield 'empty at zero' => [0, 0];
        yield 'overflow at zero' => [1, 0];
        yield 'below' => [7, 8];
        yield 'exact' => [8, 8];
        yield 'above' => [9, 8];
        yield 'exact chunk' => [8192, 8192];
        yield 'chunk overflow' => [8193, 8192];
        yield 'multiple chunks' => [20_000, 20_000];
        yield 'multiple chunks overflow' => [20_001, 20_000];
        yield 'maximum int limit does not overflow' => [5, PHP_INT_MAX];
    }

    public function testCountsBytesAndReadsOnlyTheRemainingRepresentation(): void
    {
        $body = Body::create('prefix-Привет');
        self::assertSame('prefix-', $body->read(7));
        self::assertSame('Привет', $body->readLimited(12));
        $body->close();
    }

    public function testSupportsNonSeekableInput(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        fwrite($sockets[1], 'prefix-payload');
        fclose($sockets[1]);
        $body = Body::create($sockets[0]);

        try {
            self::assertFalse($body->isSeekable());
            self::assertSame('prefix-', $body->read(7));
            self::assertSame('payload', $body->readLimited(7));
        } finally {
            $body->close();
        }
    }

    public function testStopsBeforeMaterializingALargeFile(): void
    {
        $resource = tmpfile();
        self::assertIsResource($resource);
        for ($index = 0; $index < 128; ++$index) {
            fwrite($resource, str_repeat('x', 8192));
        }
        rewind($resource);
        $body = Body::create($resource);

        try {
            $body->readLimited(65_536);
            self::fail('The file must exceed the bound');
        } catch (\LengthException) {
            self::assertSame(65_537, ftell($resource));
            self::assertFalse($body->eof());
        } finally {
            $body->close();
        }
    }

    public function testRejectsNegativeLimitsBeforeReading(): void
    {
        $body = Body::create('payload');

        try {
            $body->readLimited(-1);
            self::fail('Negative limits must fail');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $body->tell());
        } finally {
            $body->close();
        }
    }

    public function testReadFailuresRemainIoFailures(): void
    {
        $observed = new ObservedStream('payload', failRead: true);
        $body = $observed->open();

        try {
            $body->readLimited(64);
            self::fail('Read failures must propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('Observed stream read failed', $exception->getMessage());
            self::assertTrue($body->isReadable());
        } finally {
            $body->close();
        }
    }

    public function testClosedBodiesAreNotReportedAsEmpty(): void
    {
        $body = Body::create();
        $body->close();
        $this->expectException(\RuntimeException::class);
        $body->readLimited(0);
    }

    public function testStopsWhenAStreamMakesNoProgressBeforeEof(): void
    {
        $observed = new ObservedStream('payload', stallRead: true);
        $body = $observed->open();

        try {
            $body->readLimited(64);
            self::fail('An incomplete body must not be returned or retried forever');
        } catch (\RuntimeException $exception) {
            self::assertSame('HTTP body returned no data before EOF', $exception->getMessage());
            self::assertSame(1, $observed->reads);
        } finally {
            $body->close();
        }
    }
}
