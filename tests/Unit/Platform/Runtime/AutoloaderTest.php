<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Runtime;

use PHPUnit\Framework\TestCase;
use Project\Bootstrap\Autoloader;

final class AutoloaderTest extends TestCase
{
    public function testLoadsSpecificPrefixesFallbackDirectoriesClassmapsAndFunctionFiles(): void
    {
        $directory = sys_get_temp_dir() . '/autoload-' . bin2hex(random_bytes(8));
        $prefix = 'LoaderFixture' . bin2hex(random_bytes(8)) . '\\';
        $existingLoaders = spl_autoload_functions();
        $fixtures = [
            'generic/Feature/Example.php' => '<?php namespace ' . $prefix . 'Feature; final class Example { public const ORIGIN = "generic"; }',
            'specific/Example.php' => '<?php namespace ' . $prefix . 'Feature; final class Example { public const ORIGIN = "specific"; }',
            'generic/Feature/Fallback.php' => '<?php namespace ' . $prefix . 'Feature; final class Fallback {}',
            'second/Found.php' => '<?php namespace ' . $prefix . 'Other; final class Found {}',
            'odd-file.php' => '<?php namespace ' . rtrim($prefix, '\\') . '; final class Mapped {}',
            'functions/bootstrap.php' => '<?php namespace ' . $prefix . 'Functions; function bootstrap(): string { return "loaded"; }',
        ];

        try {
            foreach ($fixtures as $relative => $source) {
                $parent = \dirname($directory . '/' . $relative);
                if (!is_dir($parent)) {
                    mkdir($parent, 0o700, true);
                }
                file_put_contents($directory . '/' . $relative, $source);
            }

            Autoloader::register(
                [
                    $prefix => [$directory . '/generic'],
                    $prefix . 'Feature\\' => [$directory . '/specific'],
                    $prefix . 'Other\\' => [$directory . '/empty', $directory . '/second'],
                    $prefix . 'Functions\\' => [$directory . '/functions'],
                ],
                [$prefix . 'Mapped' => $directory . '/odd-file.php'],
                [$directory . '/functions/bootstrap.php'],
            );

            self::assertTrue(\function_exists($prefix . 'Functions\bootstrap'));
            self::assertTrue(class_exists($prefix . 'Feature\Example'));
            self::assertSame('specific', \constant($prefix . 'Feature\Example::ORIGIN'));
            self::assertTrue(class_exists($prefix . 'Feature\Fallback'));
            self::assertTrue(class_exists($prefix . 'Other\Found'));
            self::assertTrue(class_exists($prefix . 'Mapped'));
            self::assertFalse(class_exists($prefix . 'Missing'));
            // An analyzer can probe a function's name as a class in the same source file.
            self::assertFalse(class_exists($prefix . 'Functions\bootstrap'));
        } finally {
            foreach (spl_autoload_functions() as $loader) {
                if (!\in_array($loader, $existingLoaders, true)) {
                    spl_autoload_unregister($loader);
                }
            }
            if (is_dir($directory)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $file) {
                    if ($file instanceof \SplFileInfo) {
                        if ($file->isDir()) {
                            rmdir($file->getPathname());
                        } else {
                            unlink($file->getPathname());
                        }
                    }
                }
                rmdir($directory);
            }
        }
    }
}
