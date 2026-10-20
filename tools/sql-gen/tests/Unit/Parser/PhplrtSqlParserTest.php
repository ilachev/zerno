<?php

declare(strict_types=1);

namespace Tests\Unit\Parser;

use Phplrt\Contracts\Parser\ParserRuntimeExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SqlGen\Ast\DeleteQuery;
use SqlGen\Ast\InsertQuery;
use SqlGen\Ast\SelectPlaceholder;
use SqlGen\Ast\SelectProjectionCase;
use SqlGen\Ast\SelectProjectionColumn;
use SqlGen\Ast\SelectProjectionFunction;
use SqlGen\Ast\SelectProjectionWildcard;
use SqlGen\Ast\SelectQuery;
use SqlGen\Parser\PhplrtSqlParser;

final class PhplrtSqlParserTest extends TestCase
{
    public function testParsesSimpleSelect(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select id, user_id from sessions where id = :id',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertSame('sessions', $query->from->table);
        self::assertNull($query->from->alias);
        self::assertCount(2, $query->projections);
        self::assertInstanceOf(SelectProjectionColumn::class, $query->projections[0]);
        self::assertSame('id', $query->projections[0]->reference->column);
        self::assertNull($query->projections[0]->reference->table);
        self::assertCount(1, $query->where);
        self::assertSame('=', $query->where[0]->operator);
        self::assertNull($query->limit);
    }

    #[DataProvider('literalLimitQueries')]
    public function testParsesLiteralLimit(string $sql, int $expectedLimit): void
    {
        $query = (new PhplrtSqlParser())->parse($sql);

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertSame($expectedLimit, $query->limit);
        self::assertSame('sessions', $query->from->table);
        self::assertNull($query->from->alias);
    }

    /** @return iterable<string, array{string, int}> */
    public static function literalLimitQueries(): iterable
    {
        yield 'list limit without semicolon' => ['SELECT id FROM sessions LIMIT 21', 21];
        yield 'empty result' => ['SELECT id FROM sessions LIMIT 0;', 0];
        yield 'leading zeroes' => ['SELECT id FROM sessions LIMIT 00021;', 21];
        yield 'all zeroes' => ['SELECT id FROM sessions LIMIT 000;', 0];
        yield 'integer boundary' => ['SELECT id FROM sessions LIMIT ' . PHP_INT_MAX . ';', PHP_INT_MAX];
        yield 'boundary with leading zeroes' => ['SELECT id FROM sessions LIMIT 000' . PHP_INT_MAX . ';', PHP_INT_MAX];
        yield 'where' => ['SELECT id FROM sessions WHERE id = :id LIMIT 21;', 21];
        yield 'order' => ['SELECT id FROM sessions ORDER BY id DESC LIMIT 21;', 21];
        yield 'where and order' => ['SELECT id FROM sessions WHERE id < :before_id ORDER BY id DESC LIMIT 21;', 21];
    }

    public function testParsesLimitPlaceholderAfterWhereAndStableOrder(): void
    {
        $query = (new PhplrtSqlParser())->parse(
            'select s.id from sessions s where s.user_id = :user_id and s.id < :before_id order by s.updated_at desc, s.id desc limit :limit;',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertInstanceOf(SelectPlaceholder::class, $query->limit);
        self::assertSame('limit', $query->limit->name);
        self::assertCount(2, $query->where);
        self::assertSame(['and'], $query->whereOperators);
        self::assertCount(2, $query->orderBy);
        self::assertSame('updated_at', $query->orderBy[0]->column->column);
        self::assertSame('id', $query->orderBy[1]->column->column);
        self::assertSame('s', $query->orderBy[1]->column->table);
        self::assertSame('desc', $query->orderBy[1]->direction);
    }

    public function testLimitKeywordDoesNotConsumeIdentifierPrefixes(): void
    {
        $query = (new PhplrtSqlParser())->parse('SELECT limit_count FROM limit_records LIMIT :limit_count;');

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertSame('limit_records', $query->from->table);
        self::assertInstanceOf(SelectPlaceholder::class, $query->limit);
        self::assertSame('limit_count', $query->limit->name);
    }

    public function testPreservesUppercaseLimitParameterName(): void
    {
        $query = (new PhplrtSqlParser())->parse('SELECT id FROM sessions WHERE id < :LIMIT LIMIT :LIMIT;');

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertInstanceOf(SelectPlaceholder::class, $query->limit);
        self::assertSame('LIMIT', $query->limit->name);
        self::assertInstanceOf(SelectPlaceholder::class, $query->where[0]->right);
        self::assertSame('LIMIT', $query->where[0]->right->name);
    }

    #[DataProvider('overflowingLimitLiterals')]
    public function testRejectsOverflowingLiteralLimit(string $literal): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LIMIT literal must be between 0 and PHP_INT_MAX.');

        (new PhplrtSqlParser())->parse('SELECT id FROM sessions LIMIT ' . $literal . ';');
    }

    /** @return iterable<string, array{string}> */
    public static function overflowingLimitLiterals(): iterable
    {
        yield 'one above platform maximum' => [substr((string) PHP_INT_MAX, 0, -1) . '8'];
        yield 'greater than platform range' => [(string) PHP_INT_MAX . '0'];
        yield 'greater than platform range with leading zeroes' => ['000' . PHP_INT_MAX . '0'];
        yield 'arbitrary precision literal' => [str_repeat('9', 100)];
    }

    #[DataProvider('unsupportedLimitQueries')]
    public function testRejectsUnsupportedLimitSyntax(string $sql): void
    {
        $this->expectException(ParserRuntimeExceptionInterface::class);

        (new PhplrtSqlParser())->parse($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedLimitQueries(): iterable
    {
        foreach (['-1', '+1', '1.5', '1e2', "'21'", 'ALL', 'NULL', 'id', '(21)', '(:limit)', '1 + 1', ':limit + 1', ':limit::bigint', '?', '$1', '21, 0', ''] as $expression) {
            yield 'expression ' . $expression => ['SELECT id FROM sessions LIMIT ' . $expression . ';'];
        }

        yield 'repeated clause' => ['SELECT id FROM sessions LIMIT 21 LIMIT 20;'];
        yield 'limit before where' => ['SELECT id FROM sessions LIMIT 21 WHERE id = :id;'];
        yield 'limit before order' => ['SELECT id FROM sessions LIMIT 21 ORDER BY id DESC;'];
        yield 'offset after limit' => ['SELECT id FROM sessions LIMIT 21 OFFSET 0;'];
        yield 'offset before limit' => ['SELECT id FROM sessions OFFSET 0 LIMIT 21;'];
        yield 'update limit' => ['UPDATE sessions SET user_id = :user_id LIMIT 21;'];
        yield 'delete limit' => ['DELETE FROM sessions WHERE id = :id LIMIT 21;'];
        yield 'insert limit' => ['INSERT INTO sessions (id) VALUES (:id) LIMIT 21;'];
    }

    public function testParsesJoinedSelectWithAliases(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select s.id as session_id, u.email from sessions as s inner join users u on u.id = s.user_id where u.email = :email and s.user_id = :user_id',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertSame('sessions', $query->from->table);
        self::assertSame('s', $query->from->alias);
        self::assertCount(2, $query->projections);
        self::assertInstanceOf(SelectProjectionColumn::class, $query->projections[0]);
        self::assertSame('s', $query->projections[0]->reference->table);
        self::assertSame('id', $query->projections[0]->reference->column);
        self::assertSame('session_id', $query->projections[0]->alias?->value);
        self::assertCount(1, $query->joins);
        self::assertSame('inner', $query->joins[0]->type);
        self::assertSame('users', $query->joins[0]->table->table);
        self::assertSame('u', $query->joins[0]->table->alias);
        self::assertCount(2, $query->where);
        self::assertSame(['and'], $query->whereOperators);
    }

    public function testParsesQualifiedWildcardProjection(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select s.* from sessions s where s.id = :id',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertCount(1, $query->projections);
        self::assertInstanceOf(SelectProjectionWildcard::class, $query->projections[0]);
        self::assertSame('s', $query->projections[0]->table);
    }

    public function testParsesSelectWithOrderByClause(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select id, updated_at from sessions where id = :id order by updated_at desc',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertCount(2, $query->projections);
        self::assertInstanceOf(SelectProjectionColumn::class, $query->projections[1]);
        self::assertSame('updated_at', $query->projections[1]->reference->column);
        self::assertCount(1, $query->where);
        self::assertCount(1, $query->orderBy);
        self::assertSame('updated_at', $query->orderBy[0]->column->column);
        self::assertSame('desc', $query->orderBy[0]->direction);
    }

    public function testParsesCountProjectionWithAlias(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select count(*) as total from sessions where user_id = :user_id',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertCount(1, $query->projections);
        self::assertInstanceOf(SelectProjectionFunction::class, $query->projections[0]);
        self::assertSame('count', $query->projections[0]->function->name);
        self::assertTrue($query->projections[0]->function->wildcard);
        self::assertSame([], $query->projections[0]->function->arguments);
        self::assertSame('total', $query->projections[0]->alias?->value);
    }

    public function testParsesMaxProjectionWithQualifiedColumn(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select max(s.updated_at) as latest_update from sessions as s',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertCount(1, $query->projections);
        self::assertInstanceOf(SelectProjectionFunction::class, $query->projections[0]);
        self::assertSame('max', $query->projections[0]->function->name);
        self::assertFalse($query->projections[0]->function->wildcard);
        self::assertCount(1, $query->projections[0]->function->arguments);
        self::assertInstanceOf(\SqlGen\Ast\SelectColumnReference::class, $query->projections[0]->function->arguments[0]);
        self::assertSame('s', $query->projections[0]->function->arguments[0]->table);
        self::assertSame('updated_at', $query->projections[0]->function->arguments[0]->column);
        self::assertSame('latest_update', $query->projections[0]->alias?->value);
    }

    public function testParsesCoalesceProjectionWithColumnArguments(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select coalesce(user_id, fallback_user_id) as effective_user_id from sessions',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertCount(1, $query->projections);
        self::assertInstanceOf(SelectProjectionFunction::class, $query->projections[0]);
        self::assertSame('coalesce', $query->projections[0]->function->name);
        self::assertFalse($query->projections[0]->function->wildcard);
        self::assertCount(2, $query->projections[0]->function->arguments);
        self::assertSame('effective_user_id', $query->projections[0]->alias?->value);
    }

    public function testParsesCaseProjectionWithAlias(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'select case when user_id = fallback_user_id then fallback_user_id else user_id end as effective_user_id from sessions',
        );

        self::assertInstanceOf(SelectQuery::class, $query);
        self::assertCount(1, $query->projections);
        self::assertInstanceOf(SelectProjectionCase::class, $query->projections[0]);
        self::assertCount(1, $query->projections[0]->expression->whenClauses);
        self::assertSame('=', $query->projections[0]->expression->whenClauses[0]->condition->operator);
        self::assertSame('effective_user_id', $query->projections[0]->alias?->value);
    }

    public function testParsesInsertWithReturningAndConflictClause(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(<<<'SQL'
            INSERT INTO sessions (id, payload)
            VALUES (:id, :payload)
            ON CONFLICT (id) DO UPDATE SET
                payload = EXCLUDED.payload
            RETURNING *;
            SQL);

        self::assertInstanceOf(InsertQuery::class, $query);
        self::assertSame('sessions', $query->table);
        self::assertCount(2, $query->values);
        self::assertSame('id', $query->values[0]->column);
        self::assertSame('id', $query->values[0]->placeholder->name);
        self::assertNotNull($query->conflict);
        self::assertSame(['id'], $query->conflict->targetColumns);
        self::assertCount(1, $query->conflict->assignments);
        self::assertCount(1, $query->returning);
        self::assertInstanceOf(SelectProjectionWildcard::class, $query->returning[0]);
    }

    public function testParsesDeleteWithWhereClause(): void
    {
        $parser = new PhplrtSqlParser();

        $query = $parser->parse(
            'DELETE FROM sessions WHERE expires_at < :now;',
        );

        self::assertInstanceOf(DeleteQuery::class, $query);
        self::assertSame('sessions', $query->table);
        self::assertCount(1, $query->where);
        self::assertSame('<', $query->where[0]->operator);
    }
}
