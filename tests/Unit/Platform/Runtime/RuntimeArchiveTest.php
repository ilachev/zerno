<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use PHPUnit\Framework\TestCase;

final class RuntimeArchiveTest extends TestCase
{
    public function testPackagedCoreAndMigrationEntryPointWorkWithoutDevelopmentTools(): void
    {
        $root = \dirname(__DIR__, 4);
        $version = 'test-' . bin2hex(random_bytes(8));
        $archive = $root . '/dist/zerno-' . $version . '.tar.gz';
        $directory = sys_get_temp_dir() . '/zerno-runtime-' . $version;
        mkdir($directory);

        try {
            $build = $this->runProcess([PHP_BINARY, 'tools/project.php', 'build', $version], $root);
            self::assertSame(0, $build['exit'], $build['output']);
            $package = new \PharData($archive);
            $package->extractTo($directory);
            unset($package);
            foreach (['tools', 'contracts', 'tests', 'load-tests', 'third_party/codegen', 'third_party/tools'] as $excluded) {
                self::assertDirectoryDoesNotExist($directory . '/' . $excluded);
            }
            foreach (['.rr.yaml', 'public/index.php', 'bin/migrate.php', 'gen/Generated/Echo/EchoRequest.php'] as $entry) {
                self::assertFileExists($directory . '/' . $entry);
            }
            $health = $this->runProcess([PHP_BINARY, '-r', <<<'PHP'
                require 'bootstrap.php';
                $compose = require 'config/core.php';
                $app = $compose(new App\Platform\Logging\ReadableOutputLogger());
                $response = $app->handle(new App\Platform\Http\Request('GET', '/health'));
                echo $response->getStatusCode() . ' ' . $response->getBody();
                PHP], $directory);
            self::assertSame(0, $health['exit'], $health['output']);
            self::assertSame('200 {"status":"ok"}', $health['output']);
            $migration = $this->runProcess([PHP_BINARY, 'bin/migrate.php', 'invalid'], $directory);
            self::assertNotSame(0, $migration['exit']);
            self::assertStringContainsString('Invalid action. Use migrate, rollback or upgrade-metadata', $migration['output']);
            self::assertStringNotContainsString('Could not open input file', $migration['output']);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
            $this->removeDirectory($directory);
        }
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit: int, output: string}
     */
    private function runProcess(array $command, string $directory): array
    {
        $stream = tmpfile();
        self::assertIsResource($stream);

        try {
            $process = proc_open($command, [1 => $stream, 2 => $stream], $pipes, $directory);
            self::assertIsResource($process);
            $status = proc_close($process);
            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertIsString($output);

            return ['exit' => $status, 'output' => $output];
        } finally {
            fclose($stream);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $file) {
            if (!$file instanceof \SplFileInfo) {
                throw new \RuntimeException('Cannot inspect temporary test output');
            }
            if ($file->isDir() && !$file->isLink()) {
                $this->removeDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
