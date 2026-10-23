<?php

declare(strict_types=1);

namespace App\Platform\Storage\Migration;

interface Migration
{
    public function getVersion(): string;

    /** @return list<string> Each element is one complete SQL statement. */
    public function up(): array;

    /** @return list<string> Each element is one complete SQL statement. */
    public function down(): array;
}
