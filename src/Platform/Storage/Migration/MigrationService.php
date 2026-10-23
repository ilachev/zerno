<?php

declare(strict_types=1);

namespace App\Platform\Storage\Migration;

use App\Platform\Storage\Storage;

final class MigrationService
{
    /** @var array<Migration> */
    private array $migrations = [];

    public function __construct(
        private readonly Storage $storage,
        private readonly MigrationRepository $repository,
        private readonly MigrationLoader $loader,
    ) {}

    /**
     * Load all migrations from the configured directory.
     */
    public function loadMigrations(): void
    {
        $this->migrations = $this->loader->loadMigrations();
    }

    /**
     * Add a migration manually.
     */
    public function addMigration(Migration $migration): void
    {
        $this->migrations[] = $migration;
    }

    public function migrate(): void
    {
        $this->assertUniqueVersions();
        $executedMigrations = $this->repository->getExecutedMigrations();

        usort(
            $this->migrations,
            static fn(Migration $a, Migration $b) => strcmp($a->getVersion(), $b->getVersion()),
        );

        foreach ($this->migrations as $migration) {
            $version = $migration->getVersion();
            if (!\in_array($version, $executedMigrations, true)) {
                $this->storage->transaction(function () use ($migration, $version): void {
                    foreach ($migration->up() as $sql) {
                        $this->storage->execute($sql);
                    }
                    $this->repository->add($version);
                });
            }
        }
    }

    public function rollback(): void
    {
        $this->assertUniqueVersions();
        $executedMigrations = $this->repository->getExecutedMigrations();

        $migrations = array_filter(
            $this->migrations,
            static fn(Migration $migration) => \in_array($migration->getVersion(), $executedMigrations, true),
        );

        usort(
            $migrations,
            static fn(Migration $a, Migration $b) => strcmp($b->getVersion(), $a->getVersion()),
        );

        foreach ($migrations as $migration) {
            $version = $migration->getVersion();
            $this->storage->transaction(function () use ($migration, $version): void {
                foreach ($migration->down() as $sql) {
                    $this->storage->execute($sql);
                }
                $this->repository->remove($version);
            });
        }
    }

    private function assertUniqueVersions(): void
    {
        $seen = [];
        foreach ($this->migrations as $migration) {
            // Prefixing keeps numeric versions as string array keys.
            $key = 'version:' . $migration->getVersion();
            if (isset($seen[$key])) {
                throw new \LogicException('Duplicate migration version: ' . $migration->getVersion());
            }
            $seen[$key] = true;
        }
    }
}
