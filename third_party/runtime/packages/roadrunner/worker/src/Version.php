<?php

declare(strict_types=1);

namespace Spiral\RoadRunner;


final class Version
{
    public const PACKAGE_NAMES = [
        'spiral/roadrunner',
        'roadrunner/worker',
        'spiral/roadrunner-worker',
    ];
    public const VERSION_FALLBACK = 'dev-master';

    public static function current(): string
    {
        return '3.0.0';
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
