<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 3) . '/tools/catalog-process.php';

final class CatalogProcessTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('Catalog process ownership requires POSIX and PCNTL');
        }
    }

    public function testCapturesSuccessfulCommand(): void
    {
        $process = new \CatalogProcess([PHP_BINARY, '-r', 'echo "ready";'], \dirname(__DIR__, 3), []);

        try {
            self::assertSame('ready', $process->wait($this->deadline()));
        } finally {
            $process->stop();
        }
        $process->stop();
    }

    public function testRejectsNonzeroExitWithoutPublishingPrivateOutput(): void
    {
        $process = new \CatalogProcess([PHP_BINARY, '-r', 'fwrite(STDERR, "private-password"); exit(7);'], \dirname(__DIR__, 3), []);

        try {
            $process->wait($this->deadline());
            self::fail('Expected the child failure');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('status 7', $error->getMessage());
            self::assertStringNotContainsString('private-password', $error->getMessage());
        } finally {
            $process->stop();
        }
    }

    public function testBoundsRetainedOutput(): void
    {
        $process = new \CatalogProcess([PHP_BINARY, '-r', 'echo str_repeat("x", 300000);'], \dirname(__DIR__, 3), []);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('256 KiB');
            $process->wait($this->deadline());
        } finally {
            $process->stop();
        }
    }

    public function testBoundsExecutionTime(): void
    {
        $process = new \CatalogProcess([PHP_BINARY, '-r', 'sleep(30);'], \dirname(__DIR__, 3), []);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('deadline');
            $process->wait(hrtime(true) / 1_000_000_000 + 0.1);
        } finally {
            $process->stop();
        }
    }

    public function testCheckerRejectsApplicationDatabaseSettings(): void
    {
        $root = \dirname(__DIR__, 3);
        $report = tempnam(sys_get_temp_dir(), 'catalog-prerequisites-test-');
        self::assertIsString($report);
        $process = new \CatalogProcess([PHP_BINARY, $root . '/tools/check-catalog.php', '--report=' . $report], $root, [
            'DB_NAME' => 'must-not-use-application-db', 'DB_PASSWORD' => 'private-password-sentinel',
        ]);

        try {
            try {
                $process->wait($this->deadline());
                self::fail('Explicit TEST_DB_* settings are required');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('status 1', $error->getMessage());
            }
            $bytes = file_get_contents($report);
            self::assertIsString($bytes);
            $result = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $result);
            self::assertSame('failed', $result->outcome);
            self::assertSame('prerequisites', $result->failed_operation);
            self::assertSame([], $result->phases);
            self::assertStringNotContainsString('private-password-sentinel', $bytes);
            self::assertStringNotContainsString('must-not-use-application-db', $bytes);
            self::assertStringNotContainsString($root, $bytes);
        } finally {
            $process->stop();
            unlink($report);
        }
    }

    public function testStopsTheWorkerAsWellAsTheServer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'catalog-process-test-');
        self::assertIsString($file);
        $script = <<<'PHP'
            $pid = pcntl_fork();
            if ($pid < 0) { exit(2); }
            if ($pid === 0) { sleep(30); exit; }
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use ($pid): never {
                pcntl_waitpid($pid, $status);
                exit;
            });
            file_put_contents($argv[1], (string) $pid);
            sleep(30);
            PHP;
        $process = new \CatalogProcess([PHP_BINARY, '-r', $script, $file], \dirname(__DIR__, 3), []);

        try {
            $deadline = $this->deadline();

            while (file_get_contents($file) === '' && hrtime(true) / 1_000_000_000 < $deadline) {
                $process->poll();
                usleep(10_000);
            }
            $pid = file_get_contents($file);
            self::assertIsString($pid);
            self::assertTrue(ctype_digit($pid));
            $before = posix_kill((int) $pid, 0);
            $process->stop();
            $after = @posix_kill((int) $pid, 0);
            self::assertSame([true, false], [$before, $after]);
        } finally {
            $process->stop();
            unlink($file);
        }
    }

    private function deadline(): float
    {
        return hrtime(true) / 1_000_000_000 + 3;
    }
}
