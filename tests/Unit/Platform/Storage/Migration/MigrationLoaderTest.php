<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Storage\Migration;

use App\Platform\Storage\Migration\MigrationLoader;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Platform\Logging\TestLogger;

final class MigrationLoaderTest extends TestCase
{
    public function testLoadsExplicitDirectoryWithoutApplicationAutoloading(): void
    {
        $suffix = bin2hex(random_bytes(12));
        $directory = sys_get_temp_dir() . '/zerno-migration-' . $suffix;
        self::assertTrue(mkdir($directory, 0o700));
        $file = $directory . '/Migration20300101001_Records.php';
        $source = '<?php namespace MigrationFixture_' . $suffix . '; '
            . 'final readonly class Migration20300101001_Records extends \App\Platform\Storage\Migration\AbstractMigration {'
            . 'public function up(): array { return ["CREATE TABLE records (id BIGINT)"]; }'
            . 'public function down(): array { return ["DROP TABLE records"]; }}';

        try {
            self::assertSame(\strlen($source), file_put_contents($file, $source));
            $loader = new MigrationLoader($directory, new TestLogger());
            $migrations = $loader->loadMigrations();
            self::assertCount(1, $migrations);
            self::assertSame(['CREATE TABLE records (id BIGINT)'], $migrations[0]->up());
            self::assertSame(['DROP TABLE records'], $migrations[0]->down());
            self::assertCount(1, $loader->loadMigrations());
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
