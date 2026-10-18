<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SqlGen\Model\DatabaseSchema;
use SqlGen\Model\SchemaColumn;
use SqlGen\Model\SchemaTable;
use SqlGen\Model\SqlParameter;
use SqlGen\Model\SqlResultKind;
use SqlGen\Model\SqlStatement;
use SqlGen\Schema\StatementParameterResolver;
use SqlGen\Type\PhpTypeFactory;
use SqlGen\Type\PhpTypeRenderer;

final class StatementParameterResolverTest extends TestCase
{
    private PhpTypeRenderer $phpTypeRenderer;

    protected function setUp(): void
    {
        $this->phpTypeRenderer = new PhpTypeRenderer();
    }

    public function testInfersLimitOnlyParameterWithoutColumnMapping(): void
    {
        $resolved = (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT id FROM sessions ORDER BY id DESC LIMIT :row_limit;',
                parameters: [new SqlParameter('row_limit')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                ]),
            ]),
        );

        self::assertCount(1, $resolved);
        self::assertSame('row_limit', $resolved[0]->name);
        self::assertSame('rowLimit', $resolved[0]->propertyName);
        self::assertSame('BIGINT', $resolved[0]->sqlType);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertFalse($resolved[0]->nullable);
    }

    public function testPreservesStatementParameterOrderForWhereAndLimit(): void
    {
        $resolved = (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT id FROM sessions WHERE id < :before_id ORDER BY id DESC LIMIT :limit;',
                parameters: [new SqlParameter('limit'), new SqlParameter('before_id')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                ]),
            ]),
        );

        self::assertCount(2, $resolved);
        self::assertSame('limit', $resolved[0]->name);
        self::assertSame('BIGINT', $resolved[0]->sqlType);
        self::assertFalse($resolved[0]->nullable);
        self::assertSame('before_id', $resolved[1]->name);
        self::assertSame('TEXT', $resolved[1]->sqlType);
    }

    #[DataProvider('integerLimitColumnTypes')]
    public function testLimitReusedWithIntegerColumnBecomesNonNullable(string $sqlType): void
    {
        $resolved = (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::One,
                sql: 'SELECT user_id FROM sessions WHERE :limit = user_id ORDER BY user_id LIMIT :limit;',
                parameters: [new SqlParameter('limit')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'user_id' => new SchemaColumn('user_id', $sqlType, PhpTypeFactory::fromNativeType('int'), true),
                ]),
            ]),
        );

        self::assertCount(1, $resolved);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertFalse($resolved[0]->nullable);
    }

    /** @return iterable<string, array{string}> */
    public static function integerLimitColumnTypes(): iterable
    {
        foreach (['INTEGER', 'BIGINT', 'SERIAL', 'BIGSERIAL'] as $sqlType) {
            yield $sqlType => [$sqlType];
        }
    }

    #[DataProvider('incompatibleLimitColumnTypes')]
    public function testRejectsLimitReusedWithNonIntegerColumn(string $sqlType, string $phpType): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LIMIT parameter row_limit must be compatible with an integer column');

        (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT value FROM sessions WHERE value = :row_limit LIMIT :row_limit;',
                parameters: [new SqlParameter('row_limit')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'value' => new SchemaColumn('value', $sqlType, PhpTypeFactory::fromNativeType($phpType), true),
                ]),
            ]),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompatibleLimitColumnTypes(): iterable
    {
        yield 'text' => ['TEXT', 'string'];
        yield 'json' => ['JSONB', 'string'];
        yield 'boolean' => ['BOOLEAN', 'bool'];
        yield 'real' => ['REAL', 'float'];
        yield 'double' => ['DOUBLE', 'float'];
        yield 'numeric' => ['NUMERIC', 'float'];
        yield 'decimal' => ['DECIMAL', 'float'];
    }

    public function testRejectsEarlierIncompatibleMappingEvenWhenLastColumnIsInteger(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LIMIT parameter limit must be compatible with an integer column');

        (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT id FROM sessions WHERE id = :limit AND user_id = :limit LIMIT :limit;',
                parameters: [new SqlParameter('limit')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                    'user_id' => new SchemaColumn('user_id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), false),
                ]),
            ]),
        );
    }

    public function testLiteralLimitIntroducesNoParameter(): void
    {
        $resolved = (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT id FROM sessions ORDER BY id DESC LIMIT 21;',
                parameters: [],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                ]),
            ]),
        );

        self::assertSame([], $resolved);
    }

    #[DataProvider('projectionsReusingLimitParameter')]
    public function testRejectsLimitParameterReusedInProjectionExpressions(string $projection): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LIMIT parameter limit cannot be reused in projection expressions in query ListSessions');

        (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT ' . $projection . ' AS value FROM sessions LIMIT :limit;',
                parameters: [new SqlParameter('limit')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'user_id' => new SchemaColumn('user_id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), true),
                ]),
            ]),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function projectionsReusingLimitParameter(): iterable
    {
        yield 'min' => ['MIN(:limit)'];
        yield 'coalesce first argument' => ['COALESCE(:limit, user_id)'];
        yield 'coalesce later argument' => ['COALESCE(user_id, :limit)'];
        yield 'coalesce repeated parameter' => ['COALESCE(:limit, :limit)'];
        yield 'count' => ['COUNT(:limit)'];
        yield 'case condition left operand' => ['CASE WHEN :limit = user_id THEN user_id ELSE user_id END'];
        yield 'case condition right operand' => ['CASE WHEN user_id = :limit THEN user_id ELSE user_id END'];
        yield 'case result' => ['CASE WHEN user_id = user_id THEN :limit ELSE user_id END'];
        yield 'case later branch result' => ['CASE WHEN user_id = user_id THEN user_id WHEN user_id = user_id THEN :limit ELSE user_id END'];
        yield 'case else result' => ['CASE WHEN user_id = user_id THEN user_id ELSE :limit END'];
    }

    public function testAllowsSeparateProjectionParameterWithLimitParameter(): void
    {
        $resolved = (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'ListSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT COALESCE(:user_id, user_id) AS value FROM sessions WHERE user_id = :user_id LIMIT :limit;',
                parameters: [new SqlParameter('user_id'), new SqlParameter('limit')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'user_id' => new SchemaColumn('user_id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), true),
                ]),
            ]),
        );

        self::assertCount(2, $resolved);
        self::assertSame('user_id', $resolved[0]->name);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertTrue($resolved[0]->nullable);
        self::assertSame('limit', $resolved[1]->name);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[1]->phpType));
        self::assertFalse($resolved[1]->nullable);
    }

    public function testProjectionParameterNamedLimitWithoutLimitClauseKeepsColumnType(): void
    {
        $resolved = (new StatementParameterResolver())->resolve(
            new SqlStatement(
                name: 'FindSessions',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT MIN(:limit) AS value FROM sessions WHERE user_id = :limit;',
                parameters: [new SqlParameter('limit')],
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'user_id' => new SchemaColumn('user_id', 'INTEGER', PhpTypeFactory::fromNativeType('int'), true),
                ]),
            ]),
        );

        self::assertCount(1, $resolved);
        self::assertSame('limit', $resolved[0]->name);
        self::assertSame('INTEGER', $resolved[0]->sqlType);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertTrue($resolved[0]->nullable);
    }

    public function testResolvesParameterTypesFromSchemaColumns(): void
    {
        $resolver = new StatementParameterResolver();
        $schema = new DatabaseSchema([
            'sessions' => new SchemaTable('sessions', [
                'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                'user_id' => new SchemaColumn('user_id', 'INTEGER', PhpTypeFactory::fromNativeType('int'), true),
                'expires_at' => new SchemaColumn('expires_at', 'BIGINT', PhpTypeFactory::fromNativeType('int'), false),
            ]),
        ]);

        $resolved = $resolver->resolve(
            new SqlStatement(
                name: 'DeleteExpiredSessions',
                resultKind: SqlResultKind::Exec,
                sql: 'DELETE FROM sessions WHERE expires_at < :now;',
                parameters: [new SqlParameter('now')],
            ),
            $schema,
        );

        self::assertCount(1, $resolved);
        self::assertSame('now', $resolved[0]->name);
        self::assertSame('now', $resolved[0]->propertyName);
        self::assertSame('BIGINT', $resolved[0]->sqlType);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertFalse($resolved[0]->nullable);
    }

    public function testResolvesCamelCasePropertyNamesForSnakeCaseParameters(): void
    {
        $resolver = new StatementParameterResolver();
        $schema = new DatabaseSchema([
            'sessions' => new SchemaTable('sessions', [
                'user_id' => new SchemaColumn('user_id', 'INTEGER', PhpTypeFactory::fromNativeType('int'), true),
            ]),
        ]);

        $resolved = $resolver->resolve(
            new SqlStatement(
                name: 'FindSessionsByUserId',
                resultKind: SqlResultKind::Many,
                sql: 'SELECT user_id FROM sessions WHERE user_id = :user_id;',
                parameters: [new SqlParameter('user_id')],
            ),
            $schema,
        );

        self::assertCount(1, $resolved);
        self::assertSame('userId', $resolved[0]->propertyName);
        self::assertSame('INTEGER', $resolved[0]->sqlType);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertTrue($resolved[0]->nullable);
    }

    public function testResolvesInsertValueParameterTypesFromSchemaColumns(): void
    {
        $resolver = new StatementParameterResolver();
        $schema = new DatabaseSchema([
            'api_stats' => new SchemaTable('api_stats', [
                'session_id' => new SchemaColumn('session_id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                'status_code' => new SchemaColumn('status_code', 'INTEGER', PhpTypeFactory::fromNativeType('int'), false),
                'execution_time' => new SchemaColumn('execution_time', 'REAL', PhpTypeFactory::fromNativeType('float'), false),
            ]),
        ]);

        $resolved = $resolver->resolve(
            new SqlStatement(
                name: 'InsertApiStat',
                resultKind: SqlResultKind::Exec,
                sql: <<<'SQL'
                    INSERT INTO api_stats (session_id, status_code, execution_time)
                    VALUES (:session_id, :status_code, :execution_time);
                    SQL,
                parameters: [
                    new SqlParameter('session_id'),
                    new SqlParameter('status_code'),
                    new SqlParameter('execution_time'),
                ],
            ),
            $schema,
        );

        self::assertCount(3, $resolved);
        self::assertSame('string', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertSame('TEXT', $resolved[0]->sqlType);
        self::assertFalse($resolved[0]->nullable);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[1]->phpType));
        self::assertSame('INTEGER', $resolved[1]->sqlType);
        self::assertFalse($resolved[1]->nullable);
        self::assertSame('float', $this->phpTypeRenderer->renderNative($resolved[2]->phpType));
        self::assertSame('REAL', $resolved[2]->sqlType);
        self::assertFalse($resolved[2]->nullable);
    }

    public function testResolvesUpsertTableFromInsertIntoClause(): void
    {
        $resolver = new StatementParameterResolver();
        $schema = new DatabaseSchema([
            'sessions' => new SchemaTable('sessions', [
                'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                'payload' => new SchemaColumn('payload', 'JSONB', PhpTypeFactory::fromNativeType('string'), false),
            ]),
        ]);

        $resolved = $resolver->resolve(
            new SqlStatement(
                name: 'UpsertSession',
                resultKind: SqlResultKind::Exec,
                sql: <<<'SQL'
                    INSERT INTO sessions (id, payload)
                    VALUES (:id, :payload)
                    ON CONFLICT (id) DO UPDATE SET
                        payload = EXCLUDED.payload;
                    SQL,
                parameters: [
                    new SqlParameter('id'),
                    new SqlParameter('payload'),
                ],
            ),
            $schema,
        );

        self::assertCount(2, $resolved);
        self::assertSame('TEXT', $resolved[0]->sqlType);
        self::assertSame('JSONB', $resolved[1]->sqlType);
        self::assertFalse($resolved[0]->nullable);
        self::assertFalse($resolved[1]->nullable);
    }

    public function testResolvesJoinedComparisonParameters(): void
    {
        $resolver = new StatementParameterResolver();
        $schema = new DatabaseSchema([
            'sessions' => new SchemaTable('sessions', [
                'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                'user_id' => new SchemaColumn('user_id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), true),
            ]),
            'users' => new SchemaTable('users', [
                'id' => new SchemaColumn('id', 'BIGSERIAL', PhpTypeFactory::fromNativeType('int'), false),
                'email' => new SchemaColumn('email', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
            ]),
        ]);

        $resolved = $resolver->resolve(
            new SqlStatement(
                name: 'FindSessionOwners',
                resultKind: SqlResultKind::Many,
                sql: <<<'SQL'
                    SELECT s.id, u.email
                    FROM sessions AS s
                    INNER JOIN users AS u ON u.id = s.user_id
                    WHERE u.email = :email AND s.user_id = :user_id;
                    SQL,
                parameters: [
                    new SqlParameter('email'),
                    new SqlParameter('user_id'),
                ],
            ),
            $schema,
        );

        self::assertCount(2, $resolved);
        self::assertSame('email', $resolved[0]->name);
        self::assertSame('string', $this->phpTypeRenderer->renderNative($resolved[0]->phpType));
        self::assertSame('user_id', $resolved[1]->name);
        self::assertSame('int', $this->phpTypeRenderer->renderNative($resolved[1]->phpType));
        self::assertTrue($resolved[1]->nullable);
    }

}
