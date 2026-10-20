<?php

declare(strict_types=1);

namespace Tests\Unit\Generator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SqlGen\Config\DefaultSqlGenerationProfile;
use SqlGen\Config\GeneratorConfig;
use SqlGen\Generator\PhpQueryGenerator;
use SqlGen\Model\DatabaseSchema;
use SqlGen\Model\SchemaColumn;
use SqlGen\Model\SchemaTable;
use SqlGen\Model\SqlFile;
use SqlGen\Model\SqlParameter;
use SqlGen\Model\SqlResultKind;
use SqlGen\Model\SqlStatement;
use SqlGen\Type\PhpTypeFactory;

final class PhpQueryGeneratorTest extends TestCase
{
    #[DataProvider('selectResultKindsAndLimits')]
    public function testPreservesExplicitSqlAndResultKind(SqlResultKind $resultKind, string $limitClause): void
    {
        $sql = 'SELECT id FROM sessions ORDER BY id DESC' . $limitClause . ';';
        $generator = new PhpQueryGenerator(
            new GeneratorConfig(
                inputDir: 'sql/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: 'sql/schema.sql',
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), false),
                ]),
            ]),
        );

        $files = $generator->generateForSqlFile(new SqlFile(
            sourcePath: 'sql/queries/session.sql',
            moduleName: 'Session',
            statements: [new SqlStatement(
                name: 'FindSessions',
                resultKind: $resultKind,
                sql: $sql,
                parameters: [],
            )],
        ));

        self::assertCount(2, $files);
        foreach ($files as $file) {
            self::assertNotEmpty(token_get_all($file->content, TOKEN_PARSE));
        }
        self::assertStringContainsString($sql, $files[1]->content);
        self::assertStringContainsString('public static function create(): self', $files[1]->content);
        self::assertStringContainsString('return [];', $files[1]->content);
        self::assertStringContainsString(
            $resultKind === SqlResultKind::One ? 'implements OneRowQuery' : 'implements ManyRowsQuery',
            $files[1]->content,
        );
        if ($limitClause === '') {
            self::assertStringNotContainsString('LIMIT', $files[1]->content);
        }
    }

    /** @return iterable<string, array{SqlResultKind, string}> */
    public static function selectResultKindsAndLimits(): iterable
    {
        foreach ([SqlResultKind::One, SqlResultKind::Many] as $resultKind) {
            yield $resultKind->value . ' unbounded' => [$resultKind, ''];
            yield $resultKind->value . ' bounded' => [$resultKind, ' LIMIT 21'];
            yield $resultKind->value . ' zero' => [$resultKind, ' LIMIT 0'];
        }
    }

    #[DataProvider('parameterizedSelectResultKinds')]
    public function testGeneratesRequiredIntLimitAlongsideNullableWhereParameter(SqlResultKind $resultKind): void
    {
        $sql = 'SELECT id FROM sessions WHERE user_id = :user_id ORDER BY id DESC LIMIT :row_limit;';
        $generator = new PhpQueryGenerator(
            new GeneratorConfig(
                inputDir: 'sql/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: 'sql/schema.sql',
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), false),
                    'user_id' => new SchemaColumn('user_id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), true),
                ]),
            ]),
        );

        $files = $generator->generateForSqlFile(new SqlFile(
            sourcePath: 'sql/queries/session.sql',
            moduleName: 'Session',
            statements: [new SqlStatement(
                name: 'FindSessions',
                resultKind: $resultKind,
                sql: $sql,
                parameters: [new SqlParameter('user_id'), new SqlParameter('row_limit')],
            )],
        ));

        self::assertCount(2, $files);
        self::assertNotEmpty(token_get_all($files[1]->content, TOKEN_PARSE));
        self::assertStringContainsString($sql, $files[1]->content);
        self::assertStringContainsString('private int $rowLimit', $files[1]->content);
        self::assertStringContainsString('public static function create(?int $userId, int $rowLimit): self', $files[1]->content);
        self::assertStringContainsString("@return array{'user_id': null|int, 'row_limit': int}", $files[1]->content);
        self::assertStringContainsString("'user_id' => \$this->userId", $files[1]->content);
        self::assertStringContainsString("'row_limit' => \$this->rowLimit", $files[1]->content);
    }

    /** @return iterable<string, array{SqlResultKind}> */
    public static function parameterizedSelectResultKinds(): iterable
    {
        yield 'one' => [SqlResultKind::One];
        yield 'many' => [SqlResultKind::Many];
    }

    public function testGeneratesRowAndQueryFiles(): void
    {
        $generator = new PhpQueryGenerator(
            new GeneratorConfig(
                inputDir: 'sql/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: 'sql/schema.sql',
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                ]),
            ]),
        );

        $files = $generator->generateForSqlFile(
            new SqlFile(
                sourcePath: 'sql/queries/session.sql',
                moduleName: 'Session',
                statements: [
                    new SqlStatement(
                        name: 'FindSessionById',
                        resultKind: SqlResultKind::One,
                        sql: 'SELECT id FROM sessions WHERE id = :id;',
                        parameters: [new SqlParameter('id')],
                    ),
                ],
            ),
        );

        self::assertCount(2, $files);
        self::assertSame('gen/Generated/Sql/Session/FindSessionByIdRow.php', $files[0]->path);
        self::assertStringContainsString('Generated by sql-gen.  DO NOT EDIT!', $files[0]->content);
        self::assertStringContainsString('source: sql/queries/session.sql', $files[0]->content);
        self::assertStringContainsString('schema: sql/schema.sql', $files[0]->content);
        self::assertStringContainsString('final readonly class FindSessionByIdRow', $files[0]->content);
        self::assertStringContainsString('implements DatabaseRow', $files[0]->content);
        self::assertStringContainsString("@implements DatabaseRow<array{'id': string}>", $files[0]->content);
        self::assertStringContainsString("@param array{'id': string} \$row", $files[0]->content);
        self::assertStringContainsString('fromDatabaseRow', $files[0]->content);
        self::assertSame('gen/Generated/Sql/Session/FindSessionByIdQuery.php', $files[1]->path);
        self::assertStringContainsString('Generated by sql-gen.  DO NOT EDIT!', $files[1]->content);
        self::assertStringContainsString('source: sql/queries/session.sql', $files[1]->content);
        self::assertStringContainsString('schema: sql/schema.sql', $files[1]->content);
        self::assertStringContainsString('implements OneRowQuery', $files[1]->content);
        self::assertStringContainsString("@implements OneRowQuery<FindSessionByIdRow, array{'id': string}>", $files[1]->content);
        self::assertStringContainsString('private string $id', $files[1]->content);
        self::assertStringContainsString('public static function create(string $id): self', $files[1]->content);
        self::assertStringContainsString('return new self(', $files[1]->content);
        self::assertStringContainsString("@return array{'id': string}", $files[1]->content);
        self::assertStringContainsString("'id' => \$this->id", $files[1]->content);
    }

    public function testSkipsEmptyParamsClassesForQueriesWithoutParameters(): void
    {
        $generator = new PhpQueryGenerator(
            new GeneratorConfig(
                inputDir: 'sql/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: 'sql/schema.sql',
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                ]),
            ]),
        );

        $files = $generator->generateForSqlFile(
            new SqlFile(
                sourcePath: 'sql/queries/session.sql',
                moduleName: 'Session',
                statements: [
                    new SqlStatement(
                        name: 'FindAllSessions',
                        resultKind: SqlResultKind::Many,
                        sql: 'SELECT id FROM sessions;',
                        parameters: [],
                    ),
                ],
            ),
        );

        self::assertCount(2, $files);
        self::assertSame('gen/Generated/Sql/Session/FindAllSessionsRow.php', $files[0]->path);
        self::assertSame('gen/Generated/Sql/Session/FindAllSessionsQuery.php', $files[1]->path);
        self::assertStringNotContainsString('private FindAllSessionsParams $params', $files[1]->content);
        self::assertStringContainsString('public static function create(): self', $files[1]->content);
        self::assertStringContainsString('return new self();', $files[1]->content);
        self::assertStringContainsString('@return array{}', $files[1]->content);
        self::assertStringContainsString('return [];', $files[1]->content);
    }

    public function testDeduplicatesEquivalentRowShapesIntoSharedModuleRow(): void
    {
        $generator = new PhpQueryGenerator(
            new GeneratorConfig(
                inputDir: 'sql/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: 'sql/schema.sql',
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                    'user_id' => new SchemaColumn('user_id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), true),
                ]),
            ]),
        );

        $files = $generator->generateForSqlFile(
            new SqlFile(
                sourcePath: 'sql/queries/session.sql',
                moduleName: 'Session',
                statements: [
                    new SqlStatement(
                        name: 'FindSessionById',
                        resultKind: SqlResultKind::One,
                        sql: 'SELECT id, user_id FROM sessions WHERE id = :id;',
                        parameters: [new SqlParameter('id')],
                    ),
                    new SqlStatement(
                        name: 'FindSessionsByUserId',
                        resultKind: SqlResultKind::Many,
                        sql: 'SELECT id, user_id FROM sessions WHERE user_id = :user_id;',
                        parameters: [new SqlParameter('user_id')],
                    ),
                ],
            ),
        );

        self::assertCount(3, $files);
        self::assertSame('gen/Generated/Sql/Session/SessionRow.php', $files[0]->path);
        self::assertStringContainsString('final readonly class SessionRow', $files[0]->content);
        self::assertStringContainsString('implements DatabaseRow', $files[0]->content);
        self::assertStringContainsString("@implements DatabaseRow<array{'id': string, 'user_id': null|int}>", $files[0]->content);
        self::assertStringContainsString("@param array{'id': string, 'user_id': null|int} \$row", $files[0]->content);
        self::assertSame('gen/Generated/Sql/Session/FindSessionByIdQuery.php', $files[1]->path);
        self::assertStringContainsString('implements OneRowQuery', $files[1]->content);
        self::assertStringContainsString("@implements OneRowQuery<SessionRow, array{'id': string}>", $files[1]->content);
        self::assertStringContainsString('return SessionRow::class;', $files[1]->content);
        self::assertStringContainsString("@return array{'id': string}", $files[1]->content);
        self::assertSame('gen/Generated/Sql/Session/FindSessionsByUserIdQuery.php', $files[2]->path);
        self::assertStringContainsString('implements ManyRowsQuery', $files[2]->content);
        self::assertStringContainsString("@implements ManyRowsQuery<SessionRow, array{'user_id': null|int}>", $files[2]->content);
        self::assertStringContainsString('public static function create(?int $userId): self', $files[2]->content);
        self::assertStringContainsString('userId: $userId', $files[2]->content);
        self::assertStringContainsString("@return array{'user_id': null|int}", $files[2]->content);
        self::assertStringContainsString("'user_id' => \$this->userId", $files[2]->content);
    }

    public function testGeneratesRowsFromAliasedSelectColumns(): void
    {
        $generator = new PhpQueryGenerator(
            new GeneratorConfig(
                inputDir: 'sql/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: 'sql/schema.sql',
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                    'user_id' => new SchemaColumn('user_id', 'BIGINT', PhpTypeFactory::fromNativeType('int'), true),
                ]),
            ]),
        );

        $files = $generator->generateForSqlFile(
            new SqlFile(
                sourcePath: 'sql/queries/session.sql',
                moduleName: 'Session',
                statements: [
                    new SqlStatement(
                        name: 'FindAliasedSessions',
                        resultKind: SqlResultKind::Many,
                        sql: 'SELECT id AS session_id, user_id AS owner_id FROM sessions;',
                        parameters: [],
                    ),
                ],
            ),
        );

        self::assertCount(2, $files);
        self::assertStringContainsString("@implements DatabaseRow<array{'session_id': string, 'owner_id': null|int}>", $files[0]->content);
        self::assertStringContainsString('public string $sessionId', $files[0]->content);
        self::assertStringContainsString('public ?int $ownerId', $files[0]->content);
        self::assertStringContainsString("\$row['session_id']", $files[0]->content);
        self::assertStringContainsString("\$row['owner_id']", $files[0]->content);
    }

    public function testGeneratesQueriesAgainstCustomRuntimeNamespace(): void
    {
        $generator = new PhpQueryGenerator(
            new GeneratorConfig(
                inputDir: 'sql/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'Vendor\\Custom\\Sql',
                schemaPath: 'sql/schema.sql',
                profile: DefaultSqlGenerationProfile::withRuntimeNamespace('Vendor\\Runtime\\Sql'),
            ),
            new DatabaseSchema([
                'sessions' => new SchemaTable('sessions', [
                    'id' => new SchemaColumn('id', 'TEXT', PhpTypeFactory::fromNativeType('string'), false),
                ]),
            ]),
        );

        $files = $generator->generateForSqlFile(
            new SqlFile(
                sourcePath: 'sql/queries/session.sql',
                moduleName: 'Session',
                statements: [
                    new SqlStatement(
                        name: 'FindSessionById',
                        resultKind: SqlResultKind::One,
                        sql: 'SELECT id FROM sessions WHERE id = :id;',
                        parameters: [new SqlParameter('id')],
                    ),
                ],
            ),
        );

        self::assertStringContainsString('use Vendor\\Runtime\\Sql\\DatabaseRow;', $files[0]->content);
        self::assertStringContainsString('implements DatabaseRow', $files[0]->content);
        self::assertStringContainsString('use Vendor\\Runtime\\Sql\\ExecutableQuery;', $files[1]->content);
        self::assertStringContainsString('use Vendor\\Runtime\\Sql\\OneRowQuery;', $files[1]->content);
        self::assertStringContainsString('implements OneRowQuery', $files[1]->content);
    }
}
