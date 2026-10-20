<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http;

use App\Platform\Http\AttachmentResponse;
use App\Platform\Http\Body;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachmentResponseTest extends TestCase
{
    public function testCreatesAnAttachmentWithoutReadingOrRewindingTheStream(): void
    {
        $body = Body::create('payload');
        $body->seek(3);
        $response = AttachmentResponse::create($body, 'Отчёт "итоги".pdf', 7, 'application/pdf', 'report.pdf');

        try {
            self::assertSame($body, $response->getBody());
            self::assertSame(3, $body->tell());
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertSame('7', $response->getHeaderLine('Content-Length'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            self::assertSame('attachment; filename="report.pdf"; filename*=UTF-8\'\'' . rawurlencode('Отчёт "итоги".pdf'), $response->getHeaderLine('Content-Disposition'));
            self::assertFalse($response->hasHeader('Cache-Control'));
        } finally {
            $body->close();
        }
    }

    #[DataProvider('provideNormalizesUntrustedFilenamesCases')]
    public function testNormalizesUntrustedFilenames(string $name, string $fallback, string $expectedName, string $expectedFallback): void
    {
        $response = AttachmentResponse::create(Body::create(), $name, 0, fallbackFilename: $fallback);

        try {
            self::assertSame(
                'attachment; filename="' . $expectedFallback . '"; filename*=UTF-8\'\'' . rawurlencode($expectedName),
                $response->getHeaderLine('Content-Disposition'),
            );
            self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
            self::assertSame('0', $response->getHeaderLine('Content-Length'));
        } finally {
            $response->getBody()->close();
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function provideNormalizesUntrustedFilenamesCases(): iterable
    {
        yield 'paths and control characters' => ["../dir\\report\r\n.pdf\0", 'report.pdf', '_dir_report.pdf', 'report.pdf'];
        yield 'empty name' => ['', 'report.pdf', 'report.pdf', 'report.pdf'];
        yield 'dot name' => [' . . ', '..', 'download', 'download'];
        yield 'invalid UTF-8' => ["\xFF.pdf", 'report.pdf', 'report.pdf', 'report.pdf'];
        yield 'ASCII fallback' => ['Résumé.pdf', 'Résumé "100%"/\.pdf', 'Résumé.pdf', 'R__sum____100____.pdf'];
        yield 'quoted delimiter' => ['"; filename=evil.pdf', 'safe.pdf', '"; filename=evil.pdf', 'safe.pdf'];
        yield 'Unicode format controls' => ["report\u{202E}.pdf", 'safe.pdf', 'report.pdf', 'safe.pdf'];
    }

    public function testDoesNotConsumeANonSeekableStream(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        fwrite($sockets[1], 'payload');
        fclose($sockets[1]);
        $body = Body::create($sockets[0]);
        $response = AttachmentResponse::create($body, 'payload', 7);

        try {
            self::assertFalse($body->isSeekable());
            self::assertSame('payload', $response->getBody()->getContents());
        } finally {
            $body->close();
        }
    }

    #[DataProvider('provideClosesTheBodyWhenConstructionFailsCases')]
    public function testClosesTheBodyWhenConstructionFails(int $size, string $contentType): void
    {
        $body = Body::create('payload');

        try {
            AttachmentResponse::create($body, 'report', $size, $contentType);
            self::fail('Invalid attachment arguments must be rejected');
        } catch (\InvalidArgumentException) {
            self::assertFalse($body->isReadable());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideClosesTheBodyWhenConstructionFailsCases(): iterable
    {
        yield 'negative size' => [-1, 'text/plain'];
        yield 'header injection' => [7, "text/plain\r\nX-Injected: yes"];
    }
}
