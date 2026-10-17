#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Platform\DI\Container;
use App\Platform\DI\DIContainer;
use App\Platform\Storage\Storage;

require_once __DIR__ . '/bootstrap.php';

function catalogDemoPdf(string $label): string
{
    $text = 'BT /F1 18 Tf 72 750 Td (' . $label . ') Tj ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . count($offsets) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf . 'trailer << /Size ' . count($offsets) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}

function catalogDemoDirectory(): string
{
    $configured = getenv('CATALOG_FILES_DIR');
    $directory = $configured !== false && $configured !== ''
        ? $configured
        : __DIR__ . '/../../var/document-catalog/files';
    if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the catalog file directory.');
    }
    $resolved = realpath($directory);
    $public = realpath(__DIR__ . '/../../public');
    if ($resolved === false || $public === false
        || $resolved === $public || str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('The catalog file directory must be outside public.');
    }

    return $resolved;
}

function catalogDemoFile(string $directory, string $pdf): string
{
    $key = hash('sha256', $pdf) . '.pdf';
    $path = $directory . DIRECTORY_SEPARATOR . $key;
    if (is_link($path)) {
        throw new RuntimeException('Refusing a symbolic link in the catalog file directory.');
    }
    if (file_exists($path)) {
        if (!is_file($path) || hash_file('sha256', $path) !== hash('sha256', $pdf)) {
            throw new RuntimeException('An existing demo file has different content.');
        }

        return $key;
    }
    $temporary = tempnam($directory, '.seed-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create a temporary demo file.');
    }

    try {
        if (file_put_contents($temporary, $pdf) !== strlen($pdf)
            || !chmod($temporary, 0o640) || !rename($temporary, $path)) {
            throw new RuntimeException('Cannot store a demo file.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }

    return $key;
}

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Run this demo seed from the command line.');
    }
    $container = new DIContainer();
    /** @var callable(Container<object>): void $configure */
    $configure = require __DIR__ . '/config.php';
    $configure($container);
    $storage = $container->get(Storage::class);
    $directory = catalogDemoDirectory();
    // Completed files precede the transaction; a failed insert can only leave unreferenced demo files.
    $rows = [];
    foreach (range(1, 26) as $number) {
        $status = match ($number) {
            25 => 'draft',
            26 => 'withdrawn',
            default => 'published',
        };
        $pdf = catalogDemoPdf('Zerno demo document ' . $number . ' - ' . $status);
        $rows[] = [
            'title' => 'Документ №' . $number,
            'description' => 'Пример документа для публичного каталога. Состояние: ' . $status . '.',
            'status' => $status,
            'published_at' => 1792195200 + $number,
            'storage_key' => catalogDemoFile($directory, $pdf),
            'original_name' => 'document-' . $number . '.pdf',
            'file_size' => strlen($pdf),
        ];
    }
    $storage->transaction(static function () use ($storage, $rows): void {
        foreach ($rows as $row) {
            $storage->execute(
                <<<'SQL'
                    INSERT INTO catalog_documents
                        (title, description, status, published_at, storage_key, original_name, file_size)
                    VALUES
                        (:title, :description, :status, :published_at, :storage_key, :original_name, :file_size)
                    ON CONFLICT (storage_key) DO NOTHING
                    SQL,
                $row,
            );
        }
    });
    fwrite(STDOUT, "Demo fixtures ready: 24 published, one draft and one withdrawn document. Existing records were kept.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
