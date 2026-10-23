<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog;

use App\Platform\Storage\Storage;

final readonly class CatalogRepository
{
    private const string PAGE = <<<'SQL'
        SELECT id, title, description, published_at, storage_key, original_name, file_size
        FROM catalog_documents
        WHERE status = 'published'
        ORDER BY id DESC
        LIMIT 21
        SQL;
    private const string PAGE_BEFORE = <<<'SQL'
        SELECT id, title, description, published_at, storage_key, original_name, file_size
        FROM catalog_documents
        WHERE status = 'published' AND id < :before
        ORDER BY id DESC
        LIMIT 21
        SQL;
    private const string FIND_PUBLISHED = <<<'SQL'
        SELECT id, title, description, published_at, storage_key, original_name, file_size
        FROM catalog_documents
        WHERE status = 'published' AND id = :id
        SQL;

    public function __construct(private Storage $storage) {}

    /** @return list<Document> */
    public function page(?int $before = null): array
    {
        if ($before !== null && $before <= 0) {
            throw new \InvalidArgumentException('The page cursor must be a positive document ID.');
        }
        $rows = $before === null
            ? $this->storage->query(self::PAGE)
            : $this->storage->query(self::PAGE_BEFORE, ['before' => $before]);

        return array_map(self::document(...), $rows);
    }

    public function findPublished(int $id): ?Document
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->storage->queryOne(self::FIND_PUBLISHED, ['id' => $id]);

        return $row !== null ? self::document($row) : null;
    }

    /** @param array<string, scalar|null> $row */
    private static function document(array $row): Document
    {
        $id = DocumentId::parse($row['id'] ?? null);
        $fileSize = DocumentId::parse($row['file_size'] ?? null);
        $publishedAtValue = $row['published_at'] ?? null;
        $publishedAt = \is_int($publishedAtValue) || \is_string($publishedAtValue)
            ? filter_var($publishedAtValue, FILTER_VALIDATE_INT)
            : false;
        $title = $row['title'] ?? null;
        $description = $row['description'] ?? null;
        $storageKey = $row['storage_key'] ?? null;
        $originalName = $row['original_name'] ?? null;
        if ($id === null || $fileSize === null || !\is_int($publishedAt) || $publishedAt < 0
            || !\is_string($title) || !\is_string($description)
            || !\is_string($storageKey) || !\is_string($originalName)) {
            throw new \UnexpectedValueException('A published document has invalid metadata.');
        }

        return new Document($id, $title, $description, $publishedAt, $storageKey, $originalName, $fileSize);
    }
}
