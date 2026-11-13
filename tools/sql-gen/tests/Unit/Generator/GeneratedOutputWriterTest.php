<?php

declare(strict_types=1);

namespace Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use SqlGen\Generator\GeneratedFile;
use SqlGen\Generator\GeneratedOutputWriter;

final class GeneratedOutputWriterTest extends TestCase
{
    public function testFailedStagingPreservesPreviousOutputAndSuccessfulReplacementRemovesRetiredFiles(): void
    {
        $directory = sys_get_temp_dir() . '/sql-output-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/Retired.php', 'previous');
        $writer = new GeneratedOutputWriter();

        try {
            try {
                $writer->replace($directory, [
                    new GeneratedFile($directory . '/Module/First.php', 'first'),
                    new GeneratedFile($directory . '/Module/first.php', 'collision'),
                ]);
                self::fail('A colliding set must not replace usable output');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Duplicate generated PHP path', $exception->getMessage());
            }
            self::assertSame('previous', file_get_contents($directory . '/Retired.php'));
            self::assertDirectoryDoesNotExist($directory . '/Module');
            self::assertSame([], glob($directory . '.stage-*'));

            $writer->replace($directory, [new GeneratedFile($directory . '/Current.php', 'current')]);
            self::assertFileDoesNotExist($directory . '/Retired.php');
            self::assertSame('current', file_get_contents($directory . '/Current.php'));
            self::assertSame([], glob($directory . '.stage-*'));
        } finally {
            foreach (['Retired.php', 'Current.php'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            rmdir($directory);
        }
    }
}
