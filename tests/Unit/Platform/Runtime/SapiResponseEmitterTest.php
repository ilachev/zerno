<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\Body;
use App\Platform\Http\Response;
use App\Platform\Runtime\SapiResponseEmitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ObservedStream;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SapiResponseEmitterTest extends TestCase
{
    public function testEmitsEntireSeekableBodyAfterItWasRead(): void
    {
        $content = str_repeat('payload-', 4096);
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $content);
        $body = Body::create($resource);
        $body->rewind();
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
        self::assertFalse(\is_resource($resource));
        self::assertFalse($body->isReadable());
    }

    #[DataProvider('provideDoesNotReadBodyWhenHttpForbidsItCases')]
    public function testDoesNotReadBodyWhenHttpForbidsIt(int $status, string $method): void
    {
        $observed = new ObservedStream('must not be emitted');
        $body = $observed->open();

        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response($status, [], $body), $method);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('', $output);
        self::assertSame($status, http_response_code());
        self::assertSame(0, $observed->reads);
        self::assertSame(0, $observed->seeks);
        self::assertSame(1, $observed->closes);
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
        $body = Body::create($sockets[0]);
        self::assertFalse($body->isSeekable());

        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response(200, [], $body));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('non-seekable payload', $output);
        self::assertFalse(\is_resource($sockets[0]));
    }

    public function testReadsLargeBodiesInBoundedChunks(): void
    {
        $content = str_repeat('payload-', 16384);
        $observed = new ObservedStream($content);
        $body = $observed->open();

        ob_start();

        try {
            (new SapiResponseEmitter())->emit(new Response(200, [], $body));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame($content, $output);
        self::assertGreaterThan(1, $observed->reads);
        self::assertLessThanOrEqual(8192, $observed->largestRead);
        self::assertSame(1, $observed->closes);
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

    public function testClosesTheBodyWhenReadingFails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zerno-emitter-');
        self::assertNotFalse($path);
        $resource = fopen($path, 'wb');
        self::assertIsResource($resource);
        $body = Body::create($resource);
        self::assertFalse($body->isReadable());
        ob_start();

        try {
            try {
                (new SapiResponseEmitter())->emit(new Response(200, [], $body));
                self::fail('Reading a write-only stream must fail');
            } catch (\RuntimeException $exception) {
                self::assertSame('Cannot read from non-readable stream', $exception->getMessage());
                self::assertFalse(\is_resource($resource));
                self::assertFalse($body->isReadable());
            }
        } finally {
            ob_end_clean();
            $body->close();
            unlink($path);
        }
    }

    public function testRefusesToEmitAfterHeadersWereSent(): void
    {
        $autoload = \dirname(__DIR__, 4) . '/bootstrap.php';
        $script = 'require ' . var_export($autoload, true) . '; '
            . '$resource = fopen("php://temp", "w+b"); '
            . '$body = App\Platform\Http\Body::create($resource); '
            . 'echo "prefix"; '
            . 'try { (new App\Platform\Runtime\SapiResponseEmitter())->emit(new App\Platform\Http\Response(200, [], $body)); } '
            . 'catch (RuntimeException $exception) { '
            . 'fwrite(STDERR, $exception->getMessage() . (is_resource($resource) ? ":open" : ":closed")); exit(23); }';
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
        self::assertSame('Response headers have already been sent:closed', $error);
    }
}
