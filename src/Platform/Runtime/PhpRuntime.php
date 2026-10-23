<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

/** HTTP workers must use the patched upstream PHP baseline. */
final class PhpRuntime
{
    public const string MINIMUM_VERSION = '8.5.12';

    public static function assertSupported(string $version = PHP_VERSION): void
    {
        if (version_compare($version, self::MINIMUM_VERSION, '<')) {
            throw new \RuntimeException('HTTP workers require PHP ' . self::MINIMUM_VERSION . ' or newer; found ' . $version);
        }
    }
}
