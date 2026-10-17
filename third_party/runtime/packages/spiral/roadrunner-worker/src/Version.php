<?php

declare(strict_types=1);

namespace Spiral\RoadRunner;

final class Version
{
    public const PACKAGE_NAMES = [
        'spiral/roadrunner',
        'spiral/roadrunner-worker',
    ];
    public const VERSION_FALLBACK = 'dev-master';

    public static function current(): string
    {
        return '2025.1.11';
    }

    public static function constraint(): string
    {
        $current = self::current();

        if (\str_contains($current, '.')) {
            [$major] = \explode('.', $current);

            return \is_numeric($major) ? "$major.*" : '*';
        }

        return '*';
    }
}
