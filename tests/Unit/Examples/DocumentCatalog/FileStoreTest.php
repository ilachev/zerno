<?php

declare(strict_types=1);

namespace Tests\Unit\Examples\DocumentCatalog;

use Examples\DocumentCatalog\Document;
use Examples\DocumentCatalog\FileStore;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Platform\Logging\TestLogger;

final class FileStoreTest extends TestCase
{
    private string $directory;

    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/zerno-files-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . '/files');
        file_put_contents($this->directory . '/files/' . str_repeat('a', 64) . '.pdf', '%PDF-1.7');
        $this->logger = new TestLogger();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/files/*') ?: [] as $path) {
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    public function testOpensAReadOnlyFileWithoutReadingItsBody(): void
    {
        $stream = $this->store()->open($this->document());

        self::assertNotNull($stream);
        self::assertSame(0, $stream->tell());
        self::assertSame(8, $stream->getSize());
        self::assertFalse($stream->isWritable());
        self::assertSame('%PDF-1.7', $stream->getContents());
        self::assertSame([], $this->logger->logs);
        $stream->close();
    }

    public function testRejectsMalformedStorageKeysAndDoesNotLogPaths(): void
    {
        foreach (['../outside.pdf', '/tmp/file.pdf', str_repeat('A', 64) . '.pdf', str_repeat('a', 64) . '.pdf/extra', str_repeat('a', 64) . ".pdf\0"] as $key) {
            self::assertNull($this->store()->open($this->document($key)));
        }

        self::assertCount(5, $this->logger->logs);
        self::assertStringNotContainsString($this->directory, json_encode($this->logger->logs, JSON_THROW_ON_ERROR));
    }

    public function testRechecksFileAfterOperatorRecoveryInTheSameWorker(): void
    {
        $store = $this->store();
        $stream = $store->open($this->document());
        self::assertNotNull($stream);
        $stream->close();
        file_put_contents($this->directory . '/replacement.pdf', '%PDF-new');
        rename($this->directory . '/replacement.pdf', $this->directory . '/files/' . str_repeat('a', 64) . '.pdf');
        $replacement = $store->open($this->document());

        self::assertNotNull($replacement);
        self::assertSame('%PDF-new', $replacement->getContents());
        $replacement->close();
    }

    public function testRejectsMissingFileAndMetadataSizeMismatch(): void
    {
        self::assertNull($this->store()->open($this->document(str_repeat('b', 64) . '.pdf')));
        self::assertNull($this->store()->open($this->document(size: 9)));
        self::assertNull($this->store()->open($this->document(size: -1)));
        self::assertNull(new FileStore($this->directory . '/missing', $this->logger)->open($this->document()));
    }

    public function testRejectsSymlinkEscapingStorageAndDirectories(): void
    {
        file_put_contents($this->directory . '/outside.pdf', '%PDF-1.7');
        symlink($this->directory . '/outside.pdf', $this->directory . '/files/' . str_repeat('b', 64) . '.pdf');
        mkdir($this->directory . '/files/' . str_repeat('c', 64) . '.pdf');

        self::assertNull($this->store()->open($this->document(str_repeat('b', 64) . '.pdf')));
        self::assertNull($this->store()->open($this->document(str_repeat('c', 64) . '.pdf')));
    }

    private function store(): FileStore
    {
        return new FileStore($this->directory . '/files', $this->logger);
    }

    private function document(?string $key = null, int $size = 8): Document
    {
        return new Document(7, 'Document', 'Description', 1_792_195_200, $key ?? str_repeat('a', 64) . '.pdf', 'document.pdf', $size);
    }
}
