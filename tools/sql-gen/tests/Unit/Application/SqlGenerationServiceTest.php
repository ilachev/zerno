<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SqlGen\Application\SqlGenerationService;
use SqlGen\Config\DefaultSqlGenerationProfile;
use SqlGen\Config\GeneratorConfig;
use SqlGen\Config\SqlArtifactNaming;

final class SqlGenerationServiceTest extends TestCase
{
    public function testRejectsQueryAndRowClassPathCollisions(): void
    {
        $directory = sys_get_temp_dir() . '/sql-gen-collision-' . bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory . '/queries');
        file_put_contents($directory . '/schema.sql', 'CREATE TABLE users (id TEXT PRIMARY KEY);');
        file_put_contents($directory . '/queries/users.sql', "-- name: Find :many\nSELECT id FROM users;");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Generated PHP path');
            (new SqlGenerationService())->generate(new GeneratorConfig(
                $directory . '/queries',
                $directory . '/output',
                'Generated',
                $directory . '/schema.sql',
                new DefaultSqlGenerationProfile(artifactNaming: new SqlArtifactNaming(queryClassSuffix: 'Row')),
            ));
        } finally {
            unlink($directory . '/queries/users.sql');
            rmdir($directory . '/queries');
            unlink($directory . '/schema.sql');
            rmdir($directory);
        }
    }

    public function testGeneratesFilesForAllSqlSourcesInStableOrder(): void
    {
        $workspace = sys_get_temp_dir() . '/sql-gen-service-' . uniqid('', true);
        mkdir($workspace . '/queries', 0777, true);
        file_put_contents(
            $workspace . '/schema.sql',
            <<<'SQL'
            CREATE TABLE users (
                id TEXT PRIMARY KEY
            );
            SQL,
        );
        file_put_contents(
            $workspace . '/queries/beta.sql',
            <<<'SQL'
            -- name: FindBeta :many
            SELECT id
            FROM users;
            SQL,
        );
        file_put_contents(
            $workspace . '/queries/alpha.sql',
            <<<'SQL'
            -- name: FindAlpha :many
            SELECT id
            FROM users;
            SQL,
        );

        $service = new SqlGenerationService();
        $files = $service->generate(
            new GeneratorConfig(
                inputDir: $workspace . '/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: $workspace . '/schema.sql',
            ),
        );

        self::assertSame('gen/Generated/Sql/Alpha/FindAlphaQuery.php', $files[0]->path);
        self::assertSame('gen/Generated/Sql/Alpha/FindAlphaRow.php', $files[1]->path);
        self::assertSame('gen/Generated/Sql/Beta/FindBetaQuery.php', $files[2]->path);
        self::assertSame('gen/Generated/Sql/Beta/FindBetaRow.php', $files[3]->path);
    }

    public function testIncludesSourcePathAndQueryNameInGenerationErrors(): void
    {
        $workspace = sys_get_temp_dir() . '/sql-gen-service-invalid-' . uniqid('', true);
        mkdir($workspace . '/queries', 0777, true);
        file_put_contents(
            $workspace . '/schema.sql',
            <<<'SQL'
            CREATE TABLE users (
                id TEXT PRIMARY KEY
            );
            SQL,
        );
        file_put_contents(
            $workspace . '/queries/invalid.sql',
            <<<'SQL'
            -- name: FindInvalid :many
            SELECT missing_column
            FROM users;
            SQL,
        );

        $service = new SqlGenerationService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to generate SQL artifacts for query FindInvalid');
        $this->expectExceptionMessage($workspace . '/queries/invalid.sql');

        $service->generate(
            new GeneratorConfig(
                inputDir: $workspace . '/queries',
                outputDir: 'gen/Generated/Sql',
                namespace: 'App\\Generated\\Sql',
                schemaPath: $workspace . '/schema.sql',
            ),
        );
    }
}
