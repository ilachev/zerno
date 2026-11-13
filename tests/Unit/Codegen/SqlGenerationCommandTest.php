<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlGenerationCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/zerno-sql-command-' . bin2hex(random_bytes(8));
        foreach (['tools', 'sql/queries', 'gen/Generated/Sql'] as $path) {
            mkdir($this->directory . '/' . $path, 0o775, true);
        }
        $root = \dirname(__DIR__, 3);
        copy($root . '/tools/project.php', $this->directory . '/tools/project.php');
        symlink($root . '/tools/sql-gen', $this->directory . '/tools/sql-gen');
        file_put_contents($this->directory . '/sql/schema.sql', 'CREATE TABLE users (id TEXT PRIMARY KEY);');
        file_put_contents($this->directory . '/gen/Generated/Sql/Retired.php', 'previous output');
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    /** @param array<string, string> $sources */
    #[DataProvider('provideInvalidInputPreservesThePreviousGeneratedSetCases')]
    public function testInvalidInputPreservesThePreviousGeneratedSet(array $sources, string $diagnostic): void
    {
        foreach ($sources as $name => $sql) {
            file_put_contents($this->directory . '/sql/queries/' . $name . '.sql', $sql);
        }
        $result = $this->runCommand('sql:gen');
        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString($diagnostic, $result['output']);
        self::assertSame('previous output', file_get_contents($this->directory . '/gen/Generated/Sql/Retired.php'));
        self::assertSame([], glob($this->directory . '/gen/Generated/Sql.stage-*'));
        $check = $this->runCommand('sql:check');
        self::assertNotSame(0, $check['exit']);
        self::assertStringContainsString($diagnostic, $check['output']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideInvalidInputPreservesThePreviousGeneratedSetCases(): iterable
    {
        yield 'syntax error' => [['users' => "-- name: Find :many\nSELCT id FROM users;"], 'Failed to generate'];
        yield 'duplicate name' => [['users' => "-- name: SetFlag :exec\nINSERT INTO users (id) VALUES (:id);\n-- name: SetFlag :exec\nDELETE FROM users WHERE id = :id;"], 'Duplicate SQL statement name SetFlag'];
        yield 'PHP case collision' => [['users' => "-- name: Find :many\nSELECT id FROM users;\n-- name: find :many\nSELECT id FROM users;"], 'Duplicate SQL statement name find'];
        yield 'module collision' => [[
            'user-flags' => "-- name: First :many\nSELECT id FROM users;",
            'user_flags' => "-- name: Second :many\nSELECT id FROM users;",
        ], 'SQL module UserFlags'];
        yield 'empty input' => [[], 'Failed to list SQL files'];
        foreach (['LEFT', 'RIGHT', 'FULL'] as $join) {
            yield $join . ' JOIN' => [['users' => "-- name: Find :many\nSELECT a.id FROM users AS a {$join} JOIN users AS b ON a.id = b.id;"], 'Outer JOIN is not supported'];
        }
    }

    public function testSuccessfulGenerationReplacesRetiredOutputAndPassesCheck(): void
    {
        file_put_contents($this->directory . '/sql/queries/users.sql', "-- name: Find :many\nSELECT a.id FROM users AS a INNER JOIN users AS b ON a.id = b.id;");
        $result = $this->runCommand('sql:gen');
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileDoesNotExist($this->directory . '/gen/Generated/Sql/Retired.php');
        self::assertFileExists($this->directory . '/gen/Generated/Sql/Users/FindQuery.php');
        self::assertSame(0, $this->runCommand('sql:check')['exit']);
    }

    public function testDirectoryWithSqlSuffixCannotErasePreviousOutput(): void
    {
        mkdir($this->directory . '/sql/queries/empty.sql');
        $result = $this->runCommand('sql:gen');
        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('No SQL source files', $result['output']);
        self::assertSame('previous output', file_get_contents($this->directory . '/gen/Generated/Sql/Retired.php'));
    }

    /** @return array{exit: int, output: string} */
    private function runCommand(string $command): array
    {
        $stream = tmpfile();
        self::assertIsResource($stream);

        try {
            $process = proc_open([PHP_BINARY, 'tools/project.php', $command], [1 => $stream, 2 => $stream], $pipes, $this->directory);
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

    private function remove(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $file) {
            if (!$file instanceof \SplFileInfo) {
                throw new \RuntimeException('Cannot inspect temporary test output');
            }
            if ($file->isDir() && !$file->isLink()) {
                $this->remove($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
