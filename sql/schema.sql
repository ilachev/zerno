-- Schema input for the document-catalog example; runtime DDL lives in its migration.
CREATE TABLE catalog_documents (
    id BIGSERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    status TEXT NOT NULL,
    published_at BIGINT,
    storage_key TEXT,
    original_name TEXT,
    file_size BIGINT
);
