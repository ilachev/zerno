<?php

declare(strict_types=1);

namespace SqlGen\Generator;

/** Owns one dedicated SQL output directory; prepares a complete set before publication. */
final readonly class GeneratedOutputWriter
{
    /** @param list<GeneratedFile> $files */
    public function replace(string $directory, array $files): void
    {
        $directory = rtrim($directory, '/');
        if ($directory === '' || in_array(basename($directory), ['.', '..'], true) || is_link($directory)) {
            throw new \RuntimeException('SQL output must be a dedicated directory, not a symlink or filesystem root');
        }
        $parent = dirname($directory);
        $this->createDirectory($parent);
        $stage = $directory . '.stage-' . bin2hex(random_bytes(8));
        $backup = $stage . '.previous';
        $this->createDirectory($stage);

        try {
            $this->writeFiles($directory, $stage, $files);
            $existed = is_dir($directory);
            if ($existed && !rename($directory, $backup)) {
                throw new \RuntimeException('Cannot preserve previous SQL output');
            }
            if (!rename($stage, $directory)) {
                if ($existed && !rename($backup, $directory)) {
                    throw new \RuntimeException('Cannot restore previous SQL output: ' . $backup);
                }

                throw new \RuntimeException('Cannot publish generated SQL output');
            }
            $this->removeDirectory($backup);
        } finally {
            $this->removeDirectory($stage);
        }
    }

    /** @param list<GeneratedFile> $files */
    private function writeFiles(string $directory, string $stage, array $files): void
    {
        $paths = [];
        foreach ($files as $file) {
            if (!str_starts_with($file->path, $directory . '/')) {
                throw new \RuntimeException('Generated PHP is outside the SQL output: ' . $file->path);
            }
            $relative = substr($file->path, strlen($directory) + 1);
            if (array_intersect(explode('/', $relative), ['', '.', '..']) !== [] || str_contains($relative, '\\')) {
                throw new \RuntimeException('Invalid generated PHP path: ' . $file->path);
            }
            $key = strtolower($relative);
            if (isset($paths[$key])) {
                throw new \RuntimeException('Duplicate generated PHP path: ' . $file->path);
            }
            $paths[$key] = true;
            $path = $stage . '/' . $relative;
            $this->createDirectory(dirname($path));
            if (file_put_contents($path, $file->content) !== strlen($file->content)) {
                throw new \RuntimeException('Cannot write generated PHP: ' . $file->path);
            }
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create SQL output directory: ' . $directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory) as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                throw new \RuntimeException('Cannot inspect staged SQL output');
            }
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } elseif (!unlink($entry->getPathname())) {
                throw new \RuntimeException('Cannot remove staged SQL output: ' . $entry->getPathname());
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException('Cannot remove SQL output directory: ' . $directory);
        }
    }
}
