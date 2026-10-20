<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use App\Platform\Http\UploadedFile;
use App\Platform\Runtime\RoadRunnerRuntime;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;

final class RoadRunnerHttpWorkerTest extends TestCase
{
    public function testMapsNativeRequestsAndNestedUploadsWithoutChangingGlobals(): void
    {
        $original = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];
        $path = tempnam(sys_get_temp_dir(), 'zerno-rr-upload-');
        self::assertNotFalse($path);
        file_put_contents($path, 'uploaded');
        $transport = $this->createMock(WorkerInterface::class);
        $context = [
            'remoteAddr' => '192.0.2.1:1234',
            'protocol' => 'HTTP/2.0',
            'method' => 'POST',
            'uri' => 'https://example.test/form?tags%5B%5D=a&tags%5B%5D=b',
            'headers' => ['Content-Type' => ['multipart/form-data'], 'X-Multiple' => ['first', 'second'], 123 => ['numeric']],
            'cookies' => ['name' => 'value'],
            'uploads' => ['documents' => [['name' => 'file.txt', 'tmpName' => $path, 'size' => 8, 'error' => UPLOAD_ERR_OK, 'mime' => 'text/plain']]],
            'attributes' => ['trace' => 'first'],
            'rawQuery' => 'tags%5B%5D=a&tags%5B%5D=b',
            'parsed' => true,
        ];
        $transport->method('waitPayload')->willReturnOnConsecutiveCalls(
            new Payload('{"field":"value"}', json_encode($context, JSON_THROW_ON_ERROR)),
            new Payload('', json_encode(array_replace($context, [
                'method' => 'GET', 'uri' => 'http://example.test/next', 'headers' => [], 'cookies' => [],
                'uploads' => [], 'attributes' => [], 'rawQuery' => '', 'parsed' => false,
            ]), JSON_THROW_ON_ERROR)),
            null,
        );

        try {
            $worker = RoadRunnerRuntime::createWorker($transport);
            $request = $worker->waitRequest();
            self::assertNotNull($request);
            self::assertSame('POST', $request->getMethod());
            self::assertSame('2', $request->getProtocolVersion());
            self::assertSame(['first', 'second'], $request->getHeader('x-multiple'));
            self::assertSame('numeric', $request->getHeaderLine('123'));
            self::assertSame(['tags' => ['a', 'b']], $request->getQueryParams());
            self::assertSame(['field' => 'value'], $request->getParsedBody());
            self::assertSame('{"field":"value"}', (string) $request->getBody());
            self::assertSame(['name' => 'value'], $request->getCookieParams());
            self::assertSame('first', $request->getAttribute('trace'));
            self::assertSame('192.0.2.1:1234', $request->getServerParams()['REMOTE_ADDR']);
            $documents = $request->getUploadedFiles()['documents'];
            self::assertIsArray($documents);
            $upload = $documents[0];
            self::assertInstanceOf(UploadedFile::class, $upload);
            self::assertSame('uploaded', (string) $upload->getStream());
            $upload->getStream()->close();
            $next = $worker->waitRequest();
            self::assertNotNull($next);
            self::assertSame([], $next->getQueryParams());
            self::assertSame([], $next->getUploadedFiles());
            self::assertSame([], $next->getCookieParams());
            self::assertNull($next->getAttribute('trace'));
            self::assertNull($next->getParsedBody());
            self::assertSame('', (string) $next->getBody());
            self::assertNull($worker->waitRequest());
            self::assertSame($original, [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES]);
        } finally {
            unlink($path);
        }
    }
}
