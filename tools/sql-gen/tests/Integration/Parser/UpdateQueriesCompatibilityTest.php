<?php

declare(strict_types=1);

namespace Tests\Integration\Parser;

use Phplrt\Parser\Exception\UnexpectedTokenException;
use PHPUnit\Framework\TestCase;
use SqlGen\Ast\SelectProjectionWildcard;
use SqlGen\Ast\UpdateQuery;
use SqlGen\Check\PostgreSqlStatementCompiler;
use SqlGen\Config\GeneratorConfig;
use SqlGen\Generator\PhpQueryGenerator;
use SqlGen\Model\DatabaseSchema;
use SqlGen\Model\SchemaColumn;
use SqlGen\Model\SchemaTable;
use SqlGen\Model\SqlFile;
use SqlGen\Model\SqlParameter;
use SqlGen\Model\SqlResultKind;
use SqlGen\Model\SqlStatement;
use SqlGen\Parser\PhplrtSqlParser;
use SqlGen\Schema\StatementParameterResolver;
use SqlGen\Schema\StatementRowResolver;
use SqlGen\Type\PhpTypeFactory;

final class UpdateQueriesCompatibilityTest extends TestCase
{
    public function testParsesUpdateAssignmentsConditionsAndReturning(): void
    {
        $query = (new PhplrtSqlParser())->parse($this->refreshStatement()->sql);

        self::assertInstanceOf(UpdateQuery::class, $query);
        self::assertSame('sessions', $query->table);
        self::assertCount(2, $query->assignments);
        self::assertSame('expires_at', $query->assignments[0]->column);
        self::assertSame('expires_at', $query->assignments[0]->placeholder->name);
        self::assertSame('updated_at', $query->assignments[1]->column);
        self::assertCount(2, $query->where);
        self::assertSame(['and'], $query->whereOperators);
        self::assertSame('>=', $query->where[1]->operator);
        self::assertInstanceOf(SelectProjectionWildcard::class, $query->returning[0]);
    }

    public function testInfersAssignmentAndWhereParametersInDeclarationOrder(): void
    {
        $parameters = (new StatementParameterResolver())->resolve($this->refreshStatement(), $this->schema());

        self::assertSame(['expires_at', 'updated_at', 'id', 'now'], array_column($parameters, 'name'));
        self::assertSame(['BIGINT', 'BIGINT', 'TEXT', 'BIGINT'], array_column($parameters, 'sqlType'));
        self::assertSame([false, false, false, false], array_column($parameters, 'nullable'));
    }

    public function testResolvesWildcardAndExplicitReturningRows(): void
    {
        $resolver = new StatementRowResolver();
        $fields = $resolver->resolve($this->refreshStatement(), $this->schema());
        self::assertSame(['id', 'user_id', 'expires_at', 'updated_at'], array_column($fields, 'resultColumnName'));

        $statement = new SqlStatement(
            name: 'AssignSessionUser',
            resultKind: SqlResultKind::One,
            sql: 'UPDATE sessions SET user_id = :user WHERE id = :id RETURNING sessions.id AS session_id, user_id;',
            parameters: [new SqlParameter('user'), new SqlParameter('id')],
        );
        $fields = $resolver->resolve($statement, $this->schema());
        self::assertSame(['session_id', 'user_id'], array_column($fields, 'resultColumnName'));
        self::assertSame([false, true], array_column($fields, 'nullable'));
        $parameters = (new StatementParameterResolver())->resolve($statement, $this->schema());
        self::assertTrue($parameters[0]->nullable);
    }

    public function testCompilesUpdateIntoPostgreSqlPreparedStatement(): void
    {
        $compiled = (new PostgreSqlStatementCompiler())->compile($this->refreshStatement(), $this->schema());

        self::assertSame(
            'UPDATE sessions SET expires_at = $1, updated_at = $2 WHERE id = $3 AND expires_at >= $4 RETURNING *',
            $compiled->sql,
        );
        self::assertSame(['bigint', 'bigint', 'text', 'bigint'], $compiled->parameterTypes);
    }

    public function testGeneratesTypedUpdateQueryAndReturningRow(): void
    {
        $generator = new PhpQueryGenerator(
            new GeneratorConfig('queries', 'generated', 'App\\Generated\\Sql', 'schema.sql'),
            $this->schema(),
        );
        $files = $generator->generateForSqlFile(new SqlFile('queries/session.sql', 'Session', [$this->refreshStatement()]));

        self::assertCount(2, $files);
        self::assertStringContainsString('implements DatabaseRow', $files[0]->content);
        self::assertStringContainsString('public ?int $userId', $files[0]->content);
        self::assertStringContainsString('implements OneRowQuery', $files[1]->content);
        self::assertStringContainsString('private int $expiresAt', $files[1]->content);
        self::assertStringContainsString('private string $id', $files[1]->content);
        self::assertStringContainsString('private int $now', $files[1]->content);
        self::assertStringContainsString($this->refreshStatement()->sql, $files[1]->content);
    }

    public function testSupportsExecUpdateWithoutWhereAndReusesParameters(): void
    {
        $statement = new SqlStatement(
            name: 'ResetExpiry',
            resultKind: SqlResultKind::Exec,
            sql: 'UPDATE sessions SET expires_at = :timestamp, updated_at = :timestamp;',
            parameters: [new SqlParameter('timestamp')],
        );
        $compiled = (new PostgreSqlStatementCompiler())->compile($statement, $this->schema());
        self::assertSame('UPDATE sessions SET expires_at = $1, updated_at = $1', $compiled->sql);
        self::assertSame(['bigint'], $compiled->parameterTypes);
        self::assertSame([], (new StatementRowResolver())->resolve($statement, $this->schema()));
    }

    public function testResolvesReversedAndQualifiedWhereComparisons(): void
    {
        $statement = new SqlStatement(
            name: 'RefreshByTime',
            resultKind: SqlResultKind::One,
            sql: 'UPDATE sessions SET updated_at = :now WHERE :now <= sessions.expires_at OR sessions.id = :id RETURNING sessions.*;',
            parameters: [new SqlParameter('now'), new SqlParameter('id')],
        );
        $compiled = (new PostgreSqlStatementCompiler())->compile($statement, $this->schema());
        self::assertSame(
            'UPDATE sessions SET updated_at = $1 WHERE $1 <= sessions.expires_at OR sessions.id = $2 RETURNING sessions.*',
            $compiled->sql,
        );
        self::assertCount(4, (new StatementRowResolver())->resolve($statement, $this->schema()));
    }

    public function testRejectsUnknownAssignmentColumn(): void
    {
        $statement = new SqlStatement(
            name: 'InvalidUpdate',
            resultKind: SqlResultKind::Exec,
            sql: 'UPDATE sessions SET missing = :value WHERE id = :id;',
            parameters: [new SqlParameter('value'), new SqlParameter('id')],
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Column missing was not found');
        (new StatementParameterResolver())->resolve($statement, $this->schema());
    }

    public function testRejectsUnsupportedAssignmentExpressions(): void
    {
        $this->expectException(UnexpectedTokenException::class);
        (new PhplrtSqlParser())->parse('UPDATE sessions SET expires_at = updated_at WHERE id = :id;');
    }

    private function refreshStatement(): SqlStatement
    {
        return new SqlStatement(
            name: 'RefreshSession',
            resultKind: SqlResultKind::One,
            sql: 'UPDATE sessions SET expires_at = :expires_at, updated_at = :updated_at WHERE id = :id AND expires_at >= :now RETURNING *;',
            parameters: [
                new SqlParameter('expires_at'),
                new SqlParameter('updated_at'),
                new SqlParameter('id'),
                new SqlParameter('now'),
            ],
        );
    }

    private function schema(): DatabaseSchema
    {
        return new DatabaseSchema([
            'sessions' => new SchemaTable('sessions', [
                'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                'user_id' => new SchemaColumn('user_id', 'INTEGER', PhpTypeFactory::fromNativeType('int'), true),
                'expires_at' => new SchemaColumn('expires_at', 'BIGINT', PhpTypeFactory::fromNativeType('int'), false),
                'updated_at' => new SchemaColumn('updated_at', 'BIGINT', PhpTypeFactory::fromNativeType('int'), false),
            ]),
        ]);
    }
}
