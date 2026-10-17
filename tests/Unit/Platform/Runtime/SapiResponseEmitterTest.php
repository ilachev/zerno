<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Runtime\SapiResponseEmitter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SapiResponseEmitterTest extends TestCase
{
    public function testEmitsEntireSeekableBodyAfterItWasRead(): void
    {
        $content = str_repeat('payload-', 4096);
        $body = Stream::create($content);
        $body->getContents();

        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response(201, [], $body));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame($content, $output);
        self::assertSame(201, http_response_code());
    }

    #[DataProvider('provideDoesNotReadBodyWhenHttpForbidsItCases')]
    public function testDoesNotReadBodyWhenHttpForbidsIt(int $status, string $method): void
    {
        $body = Stream::create('must not be emitted');

        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response($status, [], $body), $method);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('', $output);
        self::assertSame(0, $body->tell());
        self::assertSame($status, http_response_code());
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideDoesNotReadBodyWhenHttpForbidsItCases(): iterable
    {
        yield 'HEAD success' => [200, 'HEAD'];
        yield 'HEAD error' => [500, 'HEAD'];
        yield 'informational' => [103, 'GET'];
        yield 'no content' => [204, 'GET'];
        yield 'not modified' => [304, 'GET'];
    }

    public function testStreamsNonSeekableBody(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        fwrite($sockets[1], 'non-seekable payload');
        fclose($sockets[1]);
        $body = Stream::create($sockets[0]);
        self::assertFalse($body->isSeekable());

        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response(200, [], $body));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            $body->close();
        }

        self::assertSame('non-seekable payload', $output);
    }

    public function testReadsLargeBodiesInBoundedChunks(): void
    {
        $content = str_repeat('payload-', 16384);
        $state = new class {
            public int $offset = 0;

            public int $reads = 0;
        };
        $body = $this->createMock(StreamInterface::class);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturnCallback(static fn(): bool => $state->offset >= \strlen($content));
        $body->method('read')->willReturnCallback(static function (int $length) use ($content, $state): string {
            self::assertLessThanOrEqual(65536, $length);
            $chunk = substr($content, $state->offset, $length);
            $state->offset += \strlen($chunk);
            ++$state->reads;

            return $chunk;
        });

        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response(200, [], $body));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame($content, $output);
        self::assertGreaterThan(1, $state->reads);
    }

    public function testPreservesExplicitStatusWithLocationHeader(): void
    {
        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response(200, ['Location' => '/another'], 'content'));
        } finally {
            ob_end_clean();
        }

        self::assertSame(200, http_response_code());
    }

    public function testRefusesToEmitAfterHeadersWereSent(): void
    {
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . '; '
            . 'echo "prefix"; '
            . 'try { (new App\Platform\Runtime\SapiResponseEmitter())->emit(new Nyholm\Psr7\Response()); } '
            . 'catch (RuntimeException $exception) { fwrite(STDERR, $exception->getMessage()); exit(23); }';
        $process = proc_open(
            [PHP_BINARY, '-d', 'output_buffering=0', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(23, proc_close($process));
        self::assertSame('prefix', $output);
        self::assertSame('Response headers have already been sent', $error);
    }
}
