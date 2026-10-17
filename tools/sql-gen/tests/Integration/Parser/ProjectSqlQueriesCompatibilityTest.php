<?php

declare(strict_types=1);

namespace Tests\Integration\Parser;

use PHPUnit\Framework\TestCase;
use SqlGen\Ast\DeleteQuery;
use SqlGen\Ast\InsertQuery;
use SqlGen\Ast\SelectQuery;
use SqlGen\Ast\UpdateQuery;
use SqlGen\Parser\PhplrtSqlParser;
use SqlGen\Parser\SqlFileParser;

final class ProjectSqlQueriesCompatibilityTest extends TestCase
{
    public function testParsesAllProjectSqlQueries(): void
    {
        $queryFiles = glob($this->projectRoot() . '/sql/queries/*.sql');
        if (!is_array($queryFiles) || $queryFiles === []) {
            self::fail('Project SQL query files were not found.');
        }

        $fileParser = new SqlFileParser();
        $sqlParser = new PhplrtSqlParser();

        foreach ($queryFiles as $queryFile) {
            $sqlFile = $fileParser->parseFile($queryFile);

            foreach ($sqlFile->statements as $statement) {
                $parsed = $sqlParser->parse($statement->sql);
                $normalizedSql = strtoupper(ltrim($statement->sql));

                if (str_starts_with($normalizedSql, 'SELECT')) {
                    self::assertInstanceOf(SelectQuery::class, $parsed, $statement->name);
                    continue;
                }

                if (str_starts_with($normalizedSql, 'INSERT')) {
                    self::assertInstanceOf(InsertQuery::class, $parsed, $statement->name);
                    continue;
                }

                if (str_starts_with($normalizedSql, 'UPDATE')) {
                    self::assertInstanceOf(UpdateQuery::class, $parsed, $statement->name);
                    continue;
                }

                if (str_starts_with($normalizedSql, 'DELETE')) {
                    self::assertInstanceOf(DeleteQuery::class, $parsed, $statement->name);
                    continue;
                }

                self::fail(sprintf(
                    'Unsupported SQL statement prefix for query %s from %s',
                    $statement->name,
                    basename($queryFile),
                ));
            }
        }
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 5);
    }
}
