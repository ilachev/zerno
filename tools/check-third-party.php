#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function readInventory(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read inventory: ' . $path);
    }
    $inventory = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($inventory) || array_is_list($inventory)) {
        throw new RuntimeException('Invalid inventory: ' . $path);
    }

    $result = [];
    foreach ($inventory as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('Invalid inventory key: ' . $path);
        }
        $result[$key] = $value;
    }

    return $result;
}

function checkedPath(string $directory, mixed $relativePath): string
{
    if (!is_string($relativePath) || $relativePath === '' || str_contains($relativePath, '\\')
        || str_starts_with($relativePath, '/') || in_array('..', explode('/', $relativePath), true)
        || str_contains($relativePath, "\0")) {
        throw new RuntimeException('Invalid inventory path.');
    }

    return $directory . '/' . $relativePath;
}

function checkHash(string $path, mixed $expected): void
{
    if (!is_string($expected) || preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1) {
        throw new RuntimeException('Invalid SHA-256 for: ' . $path);
    }
    if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $expected) {
        throw new RuntimeException('Integrity mismatch: ' . $path);
    }
}

try {
    $base = dirname(__DIR__) . '/third_party';
    $index = readInventory($base . '/manifest.json');
    $distributions = $index['distributions'] ?? null;
    if (!is_array($distributions) || $distributions === []) {
        throw new RuntimeException('No distributions in the inventory.');
    }
    $count = 0;
    foreach ($distributions as $distribution) {
        if (!is_array($distribution)) {
            throw new RuntimeException('Invalid distribution entry.');
        }
        $manifestPath = checkedPath($base, $distribution['manifest'] ?? null);
        checkHash($manifestPath, $distribution['sha256'] ?? null);
        $manifest = readInventory($manifestPath);
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw new RuntimeException('Empty distribution inventory: ' . $manifestPath);
        }
        $directory = dirname($manifestPath);
        foreach ($files as $relative => $hash) {
            checkHash(checkedPath($directory, $relative), $hash);
            ++$count;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $path = $file->getPathname();
            if ($file->isLink()) {
                throw new RuntimeException('Distribution symlink: ' . $path);
            }
            if ($file->isDir()) {
                continue;
            }
            if ($path === $manifestPath) {
                continue;
            }
            $relative = substr($path, strlen($directory) + 1);
            if (!array_key_exists($relative, $files)) {
                throw new RuntimeException('Unlisted distribution file: ' . $path);
            }
        }
    }
    fwrite(STDOUT, sprintf("Third-party integrity: %d distributions, %d files verified.\n", count($distributions), $count));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
