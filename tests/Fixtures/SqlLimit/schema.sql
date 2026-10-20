CREATE TABLE catalog_documents (
    id BIGSERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    status TEXT NOT NULL,
    published_at BIGINT NOT NULL,
    storage_key TEXT NOT NULL,
    original_name TEXT NOT NULL,
    file_size BIGINT NOT NULL
);

CREATE INDEX catalog_documents_status_id_idx ON catalog_documents (status, id DESC);

CREATE TABLE limit_bounds (
    narrow INTEGER NOT NULL,
    wide BIGINT NOT NULL
);
