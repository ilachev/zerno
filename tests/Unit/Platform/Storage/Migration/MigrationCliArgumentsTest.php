<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Storage\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Project\Security\Process;

final class MigrationCliArgumentsTest extends TestCase
{
    /** @param list<string> $arguments */
    #[DataProvider('provideRejectsInvalidArgumentsBeforeOpeningTheDatabaseCases')]
    public function testRejectsInvalidArgumentsBeforeOpeningTheDatabase(array $arguments, string $message): void
    {
        $result = Process::run([PHP_BINARY, \dirname(__DIR__, 5) . '/bin/migrate.php', ...$arguments], 5, 65_536);
        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString($message, $result['output']);
        self::assertStringNotContainsString('PDOException', $result['output']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideRejectsInvalidArgumentsBeforeOpeningTheDatabaseCases(): iterable
    {
        yield 'unknown action' => [['unknown'], 'Invalid action. Use migrate, rollback or upgrade-metadata'];
        yield 'metadata action with directory' => [['upgrade-metadata', 'not-a-directory'], 'upgrade-metadata does not accept a migration directory'];
    }
}
