<?php

declare(strict_types=1);

namespace SqlGen\Check;

use SqlGen\Generator\GeneratedFile;

final readonly class GeneratedOutputChecker
{
    /**
     * @param list<GeneratedFile> $expectedFiles
     */
    public function assertSynchronized(string $outputDir, array $expectedFiles): void
    {
        $expectedByPath = [];
        $pathKeys = [];

        foreach ($expectedFiles as $generatedFile) {
            $path = $this->normalizePath($generatedFile->path);
            $pathKey = strtolower($path);
            if (isset($pathKeys[$pathKey])) {
                throw new \RuntimeException('Duplicate generated PHP path: ' . $path);
            }
            $pathKeys[$pathKey] = true;
            $expectedByPath[$path] = $generatedFile->content;
        }

        $existingFiles = $this->findExistingFiles($outputDir);
        $errors = [];

        foreach ($expectedByPath as $path => $content) {
            if (!isset($existingFiles[$path])) {
                $errors[] = "Missing generated file: {$path}";

                continue;
            }

            $currentContent = file_get_contents($existingFiles[$path]);
            if (!is_string($currentContent)) {
                $errors[] = "Unable to read generated file: {$path}";

                continue;
            }

            if ($currentContent !== $content) {
                $errors[] = "Stale generated file: {$path}";
            }
        }

        foreach (array_keys($existingFiles) as $existingPath) {
            if (!isset($expectedByPath[$existingPath])) {
                $errors[] = "Unexpected generated file: {$existingPath}";
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException(implode("\n", $errors));
        }
    }

    /**
     * @return array<string, string>
     */
    private function findExistingFiles(string $outputDir): array
    {
        if (!is_dir($outputDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outputDir));
        $files = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $this->normalizePath($file->getPathname());
            $files[$path] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', ltrim($path, './'));
    }
}
