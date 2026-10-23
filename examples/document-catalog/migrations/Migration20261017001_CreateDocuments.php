<?php

declare(strict_types=1);

namespace Examples\DocumentCatalog\Migrations;

use App\Platform\Storage\Migration\AbstractMigration;

final readonly class Migration20261017001_CreateDocuments extends AbstractMigration
{
    public function up(): array
    {
        return [
            <<<'SQL'
                CREATE TABLE catalog_documents (
                    id BIGSERIAL PRIMARY KEY CHECK (id > 0),
                    title TEXT NOT NULL CHECK (btrim(title) <> ''),
                    description TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'withdrawn')),
                    published_at BIGINT CHECK (published_at >= 0),
                    storage_key TEXT UNIQUE CHECK (storage_key ~ '^[a-f0-9]{64}[.]pdf$'),
                    original_name TEXT CHECK (
                        original_name <> '' AND original_name !~ '[[:cntrl:]]'
                        AND position('/' IN original_name) = 0 AND position(chr(92) IN original_name) = 0
                    ),
                    file_size BIGINT CHECK (file_size > 0),
                    CONSTRAINT catalog_documents_published_metadata CHECK (
                        status <> 'published' OR (
                            published_at IS NOT NULL AND storage_key IS NOT NULL
                            AND original_name IS NOT NULL AND file_size IS NOT NULL
                        )
                    )
                );
                SQL,
            <<<'SQL'
                CREATE INDEX catalog_documents_published_id_idx
                    ON catalog_documents (id DESC) WHERE status = 'published';
                SQL,
        ];
    }

    public function down(): array
    {
        return ['DROP TABLE catalog_documents;'];
    }
}
