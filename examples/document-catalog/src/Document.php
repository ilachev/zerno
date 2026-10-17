<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

final readonly class Document
{
    public function __construct(
        public int $id,
        public string $title,
        public string $description,
        public int $publishedAt,
        public string $storageKey,
        public string $originalName,
        public int $fileSize,
    ) {}
}
