<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Platform\Storage\Migration\Migration;

/** Literal statements executed by the real migration service in tests. */
final readonly class SqlMigration implements Migration
{
    /** @param list<string> $up
     * @param list<string> $down
     */
    public function __construct(private string $version, private array $up, private array $down) {}

    public function getVersion(): string
    {
        return $this->version;
    }

    public function up(): array
    {
        return $this->up;
    }

    public function down(): array
    {
        return $this->down;
    }
}
