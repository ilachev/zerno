<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Http;

use App\Platform\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

final class UploadedFileTest extends TestCase
{
    public function testMovesANativeWorkerUploadOnceAndClosesTheOpenedBody(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'zerno-upload-');
        self::assertNotFalse($source);
        $target = $source . '.moved';
        file_put_contents($source, 'uploaded');

        try {
            $upload = new UploadedFile($source, 8, UPLOAD_ERR_OK, '../client.txt', 'text/plain', sapiUpload: false);
            $body = $upload->getStream();
            self::assertSame('uploaded', (string) $body);
            $upload->moveTo($target);
            self::assertFalse($body->isReadable());
            self::assertFileDoesNotExist($source);
            self::assertSame('uploaded', file_get_contents($target));
            $this->expectException(\RuntimeException::class);
            $upload->moveTo($source);
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
            if (is_file($target)) {
                unlink($target);
            }
        }
    }

    public function testSapiMoveRejectsAnOrdinaryFileEvenUnderCli(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'zerno-upload-');
        self::assertNotFalse($source);

        try {
            $upload = new UploadedFile($source, 0, UPLOAD_ERR_OK, 'file.txt', 'text/plain');
            $this->expectException(\RuntimeException::class);
            $upload->moveTo($source . '.moved');
        } finally {
            unlink($source);
        }
    }

    public function testFailedUploadsCannotBeReadOrMoved(): void
    {
        $upload = new UploadedFile('', 0, UPLOAD_ERR_PARTIAL, 'partial.txt', 'text/plain');
        self::assertSame(UPLOAD_ERR_PARTIAL, $upload->getError());
        foreach ([true, false] as $read) {
            try {
                if ($read) {
                    $upload->getStream();
                } else {
                    $upload->moveTo('/unused');
                }
                self::fail('A failed upload must not be consumed');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
