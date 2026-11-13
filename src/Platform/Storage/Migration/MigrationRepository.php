<?php

declare(strict_types=1);

namespace App\Platform\Storage\Migration;

use App\Platform\Storage\Storage;

final readonly class MigrationRepository
{
    public function __construct(
        private Storage $storage,
    ) {
        $this->createMigrationsTable();
    }

    public function createMigrationsTable(): void
    {
        $sql = <<<'SQL'
                CREATE TABLE IF NOT EXISTS migrations (
                    version TEXT PRIMARY KEY,
                    executed_at BIGINT NOT NULL
                )
            SQL;

        $this->storage->execute($sql);
    }

    /** Explicit CLI maintenance; ordinary construction does not alter an existing table. */
    public function upgradeMetadata(): void
    {
        $this->storage->transaction(function (): void {
            $this->storage->execute('ALTER TABLE migrations ALTER COLUMN executed_at TYPE BIGINT');
        });
    }

    /**
     * @return list<scalar|null>
     */
    public function getExecutedMigrations(): array
    {
        $result = $this->storage->query('SELECT version FROM migrations ORDER BY version');

        return array_column($result, 'version');
    }

    public function add(string $version): void
    {
        $this->storage->execute(
            'INSERT INTO migrations (version, executed_at) VALUES (:version, :executed_at)',
            [
                'version' => $version,
                'executed_at' => time(),
            ],
        );
    }

    public function remove(string $version): void
    {
        $this->storage->execute(
            'DELETE FROM migrations WHERE version = :version',
            ['version' => $version],
        );
    }
}
