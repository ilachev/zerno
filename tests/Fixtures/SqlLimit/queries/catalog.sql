-- name: ListFirstPage :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status
ORDER BY id DESC
LIMIT 21;

-- name: ListBefore :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT 21;

-- name: ListBounded :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT :limit;

-- name: FindFirst :one
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status
ORDER BY id DESC
LIMIT 1;

-- name: FindUnbounded :one
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status
ORDER BY id DESC;

-- name: ListUnbounded :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC;
