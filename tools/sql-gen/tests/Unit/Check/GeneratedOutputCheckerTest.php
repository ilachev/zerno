<?php

declare(strict_types=1);

namespace Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use SqlGen\Check\GeneratedOutputChecker;
use SqlGen\Generator\GeneratedFile;

final class GeneratedOutputCheckerTest extends TestCase
{
    public function testRejectsDuplicateExpectedPathsBeforeComparingOutput(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate generated PHP path');
        (new GeneratedOutputChecker())->assertSynchronized('output', [
            new GeneratedFile('output/FindQuery.php', 'first'),
            new GeneratedFile('output/findQuery.php', 'second'),
        ]);
    }

    public function testPassesWhenGeneratedFilesAreSynchronized(): void
    {
        $workspace = sys_get_temp_dir() . '/sql-gen-check-pass-' . uniqid('', true);
        mkdir($workspace . '/gen/Generated/Sql/Session', 0777, true);
        file_put_contents($workspace . '/gen/Generated/Sql/Session/Query.php', "<?php\n");

        $checker = new GeneratedOutputChecker();
        $checker->assertSynchronized(
            $workspace . '/gen/Generated/Sql',
            [
                new GeneratedFile(
                    $workspace . '/gen/Generated/Sql/Session/Query.php',
                    "<?php\n",
                ),
            ],
        );

        self::assertFileExists($workspace . '/gen/Generated/Sql/Session/Query.php');
    }

    public function testFailsWhenGeneratedFileIsStale(): void
    {
        $workspace = sys_get_temp_dir() . '/sql-gen-check-stale-' . uniqid('', true);
        mkdir($workspace . '/gen/Generated/Sql/Session', 0777, true);
        file_put_contents($workspace . '/gen/Generated/Sql/Session/Query.php', "<?php\n// stale\n");

        $checker = new GeneratedOutputChecker();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stale generated file:');

        $checker->assertSynchronized(
            $workspace . '/gen/Generated/Sql',
            [
                new GeneratedFile(
                    $workspace . '/gen/Generated/Sql/Session/Query.php',
                    "<?php\n",
                ),
            ],
        );
    }

    public function testFailsWhenUnexpectedGeneratedFileExists(): void
    {
        $workspace = sys_get_temp_dir() . '/sql-gen-check-extra-' . uniqid('', true);
        mkdir($workspace . '/gen/Generated/Sql/Session', 0777, true);
        file_put_contents($workspace . '/gen/Generated/Sql/Session/Unexpected.php', "<?php\n");

        $checker = new GeneratedOutputChecker();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected generated file:');

        $checker->assertSynchronized($workspace . '/gen/Generated/Sql', []);
    }
}
