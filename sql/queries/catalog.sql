-- name: ListPublished :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status
ORDER BY id DESC
LIMIT 21;

-- name: ListPublishedBefore :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT 21;

-- name: FindPublished :one
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id = :id
LIMIT 1;
