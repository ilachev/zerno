<?php

declare(strict_types=1);

namespace Project\ProtoGen;

/** The caller grants ownership of one dedicated generated directory. */
final class Output
{
    /** @param array<string, string> $files */
    public static function check(string $directory, array $files): void
    {
        $actual = [];
        if (is_dir($directory) && !is_link($directory)) {
            foreach (new \FilesystemIterator($directory) as $entry) {
                if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                    throw new \RuntimeException('Unexpected generated output entry');
                }
                $actual[$entry->getFilename()] = self::read($entry->getPathname());
            }
        }
        ksort($actual);
        ksort($files);
        if ($actual !== $files) {
            throw new \RuntimeException('Missing, extra or stale generated PHP; run proto:gen');
        }
    }

    /** @param array<string, string> $files */
    public static function replace(string $directory, array $files): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('Generated output cannot be a symlink');
        }
        $parent = \dirname($directory);
        if (!is_dir($parent) && !mkdir($parent, 0o775, true) && !is_dir($parent)) {
            throw new \RuntimeException('Cannot create generated parent directory');
        }
        $stage = $directory . '.stage-' . bin2hex(random_bytes(8));
        $backup = $stage . '.previous';
        if (!mkdir($stage)) {
            throw new \RuntimeException('Cannot stage generated output');
        }

        try {
            foreach ($files as $name => $bytes) {
                if (basename($name) !== $name || file_put_contents($stage . '/' . $name, $bytes) !== \strlen($bytes)) {
                    throw new \RuntimeException('Cannot write generated PHP');
                }
            }
            $existed = is_dir($directory);
            if ($existed && !rename($directory, $backup)) {
                throw new \RuntimeException('Cannot preserve previous generated output');
            }
            if (!rename($stage, $directory)) {
                if ($existed && !rename($backup, $directory)) {
                    throw new \RuntimeException('Cannot restore previous output: ' . $backup);
                }

                throw new \RuntimeException('Cannot publish generated output');
            }
            self::remove($backup);
        } finally {
            self::remove($stage);
        }
    }

    public static function read(string $path): string
    {
        if (is_link($path) || !is_file($path) || filesize($path) > 4_194_304) {
            throw new \RuntimeException('Missing, symlinked or oversized generator input: ' . $path);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read: ' . $path);
        }

        return $bytes;
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory) as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                throw new \RuntimeException('Cannot read generated directory');
            }
            if ($entry->isDir() && !$entry->isLink()) {
                self::remove($entry->getPathname());
            } elseif (!unlink($entry->getPathname())) {
                throw new \RuntimeException('Cannot remove stale generated output');
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException('Cannot remove generated directory');
        }
    }
}
