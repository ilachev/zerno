<?php

declare(strict_types=1);

namespace Project\Bootstrap;

final class Autoloader
{
    /**
     * @param array<string, list<string>> $prefixes
     * @param array<string, string> $classmap
     * @param list<string> $files
     */
    public static function register(array $prefixes, array $classmap = [], array $files = []): void
    {
        uksort($prefixes, static fn(string $left, string $right): int => \strlen($right) <=> \strlen($left) ?: strcmp($left, $right));

        spl_autoload_register(static function (string $class) use ($prefixes, $classmap): void {
            if (strpbrk($class, "./\0") !== false) {
                return;
            }

            if (isset($classmap[$class])) {
                require_once $classmap[$class];

                return;
            }

            foreach ($prefixes as $prefix => $directories) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
                foreach ($directories as $directory) {
                    $path = $directory . '/' . $relativePath;
                    if (is_file($path)) {
                        require_once $path;

                        return;
                    }
                }
            }
        });

        foreach ($files as $file) {
            require_once $file;
        }
    }
}
